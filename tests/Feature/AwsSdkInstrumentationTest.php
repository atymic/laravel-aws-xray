<?php

declare(strict_types=1);

use Atymic\Xray\Instrumentation\AwsSdkInstrumentation;
use Atymic\Xray\Serializer\XraySegmentSerializer;
use Atymic\Xray\Trace\SpanKind;
use Atymic\Xray\Trace\SpanStatus;
use Aws\Command;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\Sqs\SqsClient;
use GuzzleHttp\Psr7\Response;

/**
 * @param  list<Result|AwsException>  $queue
 */
function sqsClient(array $queue = []): SqsClient
{
    $handler = new MockHandler;

    foreach ($queue === [] ? [new Result(['@metadata' => ['statusCode' => 200]])] : $queue as $item) {
        $handler->append($item);
    }

    return new SqsClient([
        'region' => 'us-east-1',
        'version' => 'latest',
        'credentials' => ['key' => 'k', 'secret' => 's'],
        'handler' => $handler,
        // Otherwise the SDK retries a 5xx and drains the mock queue.
        'retries' => 0,
    ]);
}

function awsError(string $code, int $status): AwsException
{
    return new AwsException('failed', new Command('SendMessage'), [
        'code' => $code,
        'response' => new Response($status),
    ]);
}

beforeEach(function (): void {
    registerInstrumentation(AwsSdkInstrumentation::class);
});

afterEach(function (): void {
    AwsSdkInstrumentation::forget();
});

it('records a client span for an sdk call', function (): void {
    $client = AwsSdkInstrumentation::instrument(sqsClient());

    withTrace(function () use ($client): void {
        $client->sendMessage([
            'QueueUrl' => 'https://sqs.us-east-1.amazonaws.com/1/emails',
            'MessageBody' => 'hello',
        ]);
    });

    $span = recordedSpan('sqs.SendMessage');

    expect($span)->not->toBeNull()
        ->and($span->kind)->toBe(SpanKind::Client)
        ->and($span->attributes())->toMatchArray([
            'rpc.system' => 'aws-api',
            'rpc.service' => 'sqs',
            'rpc.method' => 'SendMessage',
            'aws.region' => 'us-east-1',
            'aws.queue_url' => 'https://sqs.us-east-1.amazonaws.com/1/emails',
            'http.response.status_code' => 200,
        ]);
});

it('does not capture the message body', function (): void {
    // Command payloads carry user data, and spans are billed by the byte.
    $client = AwsSdkInstrumentation::instrument(sqsClient());

    withTrace(function () use ($client): void {
        $client->sendMessage([
            'QueueUrl' => 'https://sqs.us-east-1.amazonaws.com/1/emails',
            'MessageBody' => 'super-secret-payload',
        ]);
    });

    expect(json_encode(recordedSpan('sqs.SendMessage')->attributes()))
        ->not->toContain('super-secret-payload');
});

it('times the call rather than the dispatch', function (): void {
    // The SDK is promise-based even for synchronous calls, so a span closed
    // after the handler returns would measure nothing.
    $handler = new MockHandler;
    $handler->append(function () {
        usleep(20_000);

        return new Result(['@metadata' => ['statusCode' => 200]]);
    });

    $client = AwsSdkInstrumentation::instrument(new SqsClient([
        'region' => 'us-east-1',
        'version' => 'latest',
        'credentials' => ['key' => 'k', 'secret' => 's'],
        'handler' => $handler,
    ]));

    withTrace(function () use ($client): void {
        $client->sendMessage(['QueueUrl' => 'https://q', 'MessageBody' => 'x']);
    });

    expect(recordedSpan('sqs.SendMessage')->duration())->toBeGreaterThan(0.015);
});

it('marks a failed call as a fault and rethrows', function (): void {
    $client = AwsSdkInstrumentation::instrument(sqsClient([awsError('InternalError', 500)]));

    withTrace(function () use ($client): void {
        try {
            $client->sendMessage(['QueueUrl' => 'https://q', 'MessageBody' => 'x']);
        } catch (AwsException) {
            // The SDK's own error handling must still see the exception.
        }
    });

    $span = recordedSpan('sqs.SendMessage');

    expect($span->status())->toBe(SpanStatus::Fault)
        ->and($span->attributes()['error.type'])->toBe('InternalError');
});

it('distinguishes throttling from a fault', function (): void {
    // Throttling is its own X-Ray state and is the signal that matters when a
    // downstream service is the bottleneck.
    $client = AwsSdkInstrumentation::instrument(sqsClient([awsError('ThrottlingException', 400)]));

    withTrace(function () use ($client): void {
        try {
            $client->sendMessage(['QueueUrl' => 'https://q', 'MessageBody' => 'x']);
        } catch (AwsException) {
        }
    });

    expect(recordedSpan('sqs.SendMessage')->status())->toBe(SpanStatus::Throttle);
});

it('emits the aws namespace so the call becomes a service map node', function (): void {
    $client = AwsSdkInstrumentation::instrument(sqsClient());

    withTrace(function () use ($client): void {
        $client->sendMessage([
            'QueueUrl' => 'https://sqs.us-east-1.amazonaws.com/1/emails',
            'MessageBody' => 'x',
        ]);
    });

    $serializer = app(XraySegmentSerializer::class);
    $document = $serializer->serialize(recordedSpan('sqs.SendMessage'));

    expect($document['namespace'])->toBe('aws')
        ->and($document['aws'])->toMatchArray([
            'operation' => 'SendMessage',
            'region' => 'us-east-1',
            'queue_url' => 'https://sqs.us-east-1.amazonaws.com/1/emails',
        ]);
});

it('instruments any client resolved through the container', function (): void {
    // The SDK has no global middleware registry, so container resolution is
    // what makes this automatic for clients the application never hands us.
    app()->bind('test-sqs', fn () => sqsClient());

    $client = app('test-sqs');

    withTrace(function () use ($client): void {
        $client->sendMessage(['QueueUrl' => 'https://q', 'MessageBody' => 'x']);
    });

    expect(recordedSpan('sqs.SendMessage'))->not->toBeNull();
});

it('stays quiet outside a trace', function (): void {
    // A queue worker between jobs has no trace open; spans must not accumulate.
    $client = AwsSdkInstrumentation::instrument(sqsClient());

    $client->sendMessage(['QueueUrl' => 'https://q', 'MessageBody' => 'x']);

    expect(recordedSpans())->toBeEmpty();
});

it('does not stack middleware when instrumented twice', function (): void {
    $client = sqsClient();

    AwsSdkInstrumentation::instrument($client);
    AwsSdkInstrumentation::instrument($client);

    withTrace(function () use ($client): void {
        $client->sendMessage(['QueueUrl' => 'https://q', 'MessageBody' => 'x']);
    });

    expect(collect(recordedSpans())->where('name', 'sqs.SendMessage'))->toHaveCount(1);
});
