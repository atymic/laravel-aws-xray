<?php

declare(strict_types=1);

namespace Atymic\Xray\Instrumentation;

use Atymic\Xray\Trace\Span;
use Atymic\Xray\Trace\SpanKind;
use Atymic\Xray\Trace\SpanStatus;
use Atymic\Xray\Tracer;
use Aws\AwsClientInterface;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\ResultInterface;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

/**
 * Spans for AWS SDK calls, so downstream services appear as their own nodes on
 * the service map rather than as unexplained gaps inside a request.
 *
 * The SDK has no global middleware registry — middleware attaches to a client's
 * handler list, per client. So this hooks the container: any AWS client
 * *resolved through Laravel* is instrumented automatically. Clients constructed
 * with `new S3Client(...)` are invisible to the container and must be passed to
 * {@see self::instrument()} by hand.
 *
 * Attributes follow OTel's `rpc.*` conventions; `XraySegmentSerializer` keys off
 * `rpc.system === 'aws-api'` to emit the X-Ray `aws` block with
 * `namespace: aws`, which is what draws the downstream node.
 *
 * @see https://opentelemetry.io/docs/specs/semconv/rpc/aws-sdk/
 */
final class AwsSdkInstrumentation implements Instrumentation
{
    private static ?Tracer $tracer = null;

    public function register(Application $app, array $options = []): void
    {
        self::$tracer = $app->make(Tracer::class);

        // Fires for every object leaving the container, so clients built by
        // any service provider get instrumented without naming them here.
        // `instanceof` against a missing interface is false without triggering
        // autoload, so this is inert — not fatal — when the SDK is not
        // installed. That is why no class_exists guard is needed.
        $app->resolving(static function (mixed $object): void {
            if ($object instanceof AwsClientInterface) {
                self::instrument($object);
            }
        });
    }

    /**
     * Clients already carrying our middleware.
     *
     * `appendInit` stacks rather than replacing, so instrumenting a client
     * twice would emit two spans per call. The container's `resolving` hook
     * fires on every resolution of a shared binding, which makes that the
     * normal case rather than an edge case.
     *
     * Weak, so a client going out of scope is not kept alive by this.
     *
     * @var \WeakMap<AwsClientInterface, true>|null
     */
    private static ?\WeakMap $instrumented = null;

    /**
     * Attach tracing to a client the container never saw.
     *
     * Idempotent — safe to call on a client that already has it.
     */
    public static function instrument(AwsClientInterface $client): AwsClientInterface
    {
        self::$instrumented ??= new \WeakMap;

        if (isset(self::$instrumented[$client])) {
            return $client;
        }

        self::$instrumented[$client] = true;

        $client->getHandlerList()->appendInit(
            static fn (callable $handler): callable => static function (
                CommandInterface $command,
                mixed $request = null,
            ) use ($handler, $client) {
                $tracer = self::$tracer;

                if ($tracer === null || ! $tracer->isRecording()) {
                    return $handler($command, $request);
                }

                $service = self::serviceName($client);

                $span = $tracer->startSpan(
                    name: $service.'.'.$command->getName(),
                    kind: SpanKind::Client,
                    attributes: array_filter([
                        'rpc.system' => 'aws-api',
                        'rpc.service' => $service,
                        'rpc.method' => $command->getName(),
                        'aws.region' => self::region($client),
                    ], static fn ($value) => $value !== null),
                );

                if ($span === null) {
                    return $handler($command, $request);
                }

                self::describeTarget($span, $command);

                // The SDK is promise-based even for synchronous calls — `wait()`
                // resolves the same promise — so the span must be closed in the
                // promise callbacks. Closing it after `$handler()` returns would
                // time only the dispatch, not the call.
                return $handler($command, $request)->then(
                    static function (mixed $result) use ($tracer, $span) {
                        self::recordResult($span, $result);
                        $tracer->endSpan($span);

                        return $result;
                    },
                    static function (mixed $reason) use ($tracer, $span) {
                        self::recordFailure($span, $reason);
                        $tracer->endSpan($span);

                        // Rethrowing preserves the SDK's own error handling —
                        // retries included — rather than swallowing it.
                        throw $reason instanceof Throwable
                            ? $reason
                            : new \RuntimeException('aws sdk call failed');
                    },
                );
            },
            'xray.trace',
        );

        return $client;
    }

    /**
     * Identify the resource being addressed, which is what makes one node on
     * the service map distinguishable from another.
     *
     * Deliberately a short allow-list: command payloads carry message bodies
     * and item attributes, and spans are billed by the byte.
     */
    private static function describeTarget(Span $span, CommandInterface $command): void
    {
        $arguments = $command->toArray();

        foreach ([
            'QueueUrl' => 'aws.queue_url',
            'TableName' => 'aws.table_name',
            'Bucket' => 'aws.bucket_name',
            'Key' => 'aws.key',
            'FunctionName' => 'aws.function_name',
            'TopicArn' => 'aws.topic_arn',
            'StateMachineArn' => 'aws.state_machine_arn',
        ] as $argument => $attribute) {
            $value = $arguments[$argument] ?? null;

            if (is_string($value) && $value !== '') {
                $span->setAttribute($attribute, $value);
            }
        }
    }

    private static function recordResult(Span $span, mixed $result): void
    {
        if (! $result instanceof ResultInterface) {
            return;
        }

        $metadata = $result['@metadata'] ?? null;

        if (! is_array($metadata)) {
            return;
        }

        if (isset($metadata['statusCode'])) {
            $status = (int) $metadata['statusCode'];

            $span->setAttribute('http.response.status_code', $status)
                ->setStatus(SpanStatus::fromHttpStatus($status));
        }

        if (isset($metadata['headers']['x-amzn-requestid'])) {
            $span->setAttribute('aws.request_id', (string) $metadata['headers']['x-amzn-requestid']);
        }
    }

    private static function recordFailure(Span $span, mixed $reason): void
    {
        $span->setStatus(SpanStatus::Fault);

        if ($reason instanceof AwsException) {
            $span->setAttribute('aws.request_id', $reason->getAwsRequestId())
                ->setAttribute('error.type', $reason->getAwsErrorCode())
                // Throttling is its own X-Ray state, distinct from a fault, and
                // is the signal that matters when a downstream is the problem.
                ->setStatus($reason->isConnectionError() || self::isThrottle($reason)
                    ? SpanStatus::Throttle
                    : SpanStatus::Fault);

            if (($status = $reason->getStatusCode()) !== null) {
                $span->setAttribute('http.response.status_code', $status);
            }

            return;
        }

        if ($reason instanceof Throwable) {
            $span->recordException($reason);
        }
    }

    private static function isThrottle(AwsException $exception): bool
    {
        return $exception->getStatusCode() === 429
            || in_array($exception->getAwsErrorCode(), [
                'Throttling',
                'ThrottlingException',
                'RequestThrottled',
                'RequestThrottledException',
                'TooManyRequestsException',
                'ProvisionedThroughputExceededException',
                'SlowDown',
            ], true);
    }

    private static function serviceName(AwsClientInterface $client): string
    {
        try {
            $api = $client->getApi();

            $name = $api->getEndpointPrefix();

            return is_string($name) && $name !== '' ? $name : $api->getServiceName();
        } catch (Throwable) {
            return 'aws';
        }
    }

    private static function region(AwsClientInterface $client): ?string
    {
        try {
            $region = $client->getRegion();

            return is_string($region) && $region !== '' ? $region : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Testing seam — the tracer is static because SDK middleware outlives the
     * container that registered it.
     */
    public static function forget(): void
    {
        self::$tracer = null;
        self::$instrumented = null;
    }
}
