<?php

declare(strict_types=1);

namespace Atymic\Xray\Instrumentation;

use Atymic\Xray\Lambda\ColdStart;
use Atymic\Xray\Trace\Span;
use Atymic\Xray\Trace\SpanKind;
use Atymic\Xray\Trace\SpanStatus;
use Atymic\Xray\Trace\TraceHeader;
use Atymic\Xray\Tracer;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Queue;

/**
 * Spans for queue work, on both sides of the queue.
 *
 * Trace context is carried in the job payload, so a job dispatched during a web
 * request and run later by a worker appears under the same trace. On SQS the
 * context is *also* read from the `AWSTraceHeader` system attribute when
 * present, since that is what AWS services themselves populate.
 */
final class QueueInstrumentation implements Instrumentation
{
    /** Payload key carrying the trace header between producer and consumer. */
    public const PAYLOAD_KEY = 'xray_trace_header';

    public function register(Application $app, array $options = []): void
    {
        $events = $app['events'];
        $tracer = $app->make(Tracer::class);

        $this->registerProducer($tracer);
        $this->registerDispatchSpan($events, $tracer);
        $this->registerConsumer($events, $tracer);
    }

    /**
     * Inject trace context into every job payload.
     *
     * `createPayloadUsing` covers all dispatch paths — including bulk dispatch,
     * which does not go through `pushRaw` and would otherwise silently lose
     * context.
     */
    private function registerProducer(Tracer $tracer): void
    {
        Queue::createPayloadUsing(static function () use ($tracer): array {
            $header = $tracer->propagationHeader();

            if ($header === null || ! $header->hasTrace()) {
                return [];
            }

            return [self::PAYLOAD_KEY => (string) $header];
        });
    }

    private function registerDispatchSpan(mixed $events, Tracer $tracer): void
    {
        $events->listen(JobQueued::class, static function (JobQueued $event) use ($tracer): void {
            if (! $tracer->isRecording()) {
                return;
            }

            $name = is_object($event->job) ? $event->job::class : (string) $event->job;

            $span = $tracer->startSpan(
                name: 'send '.($event->queue ?? 'default'),
                kind: SpanKind::Producer,
                attributes: [
                    'messaging.system' => 'laravel',
                    'messaging.destination.name' => $event->queue ?? 'default',
                    'messaging.message.id' => (string) $event->id,
                    'messaging.operation.name' => 'send',
                    'messaging.message.job_name' => $name,
                ],
            );

            $tracer->endSpan($span);
        });
    }

    private function registerConsumer(mixed $events, Tracer $tracer): void
    {
        $current = null;

        $events->listen(JobProcessing::class, function (JobProcessing $event) use ($tracer, &$current): void {
            $payload = $event->job->payload();

            // Each job gets its own trace context. On Lambda one invocation can
            // deliver up to ten SQS messages from ten different producers, so
            // context must be resolved per job, never per invocation.
            $tracer->startTrace($this->inboundHeader($event, $payload));

            $current = $tracer->startSpan(
                name: 'process '.$event->job->getQueue(),
                kind: SpanKind::Consumer,
                attributes: [
                    'messaging.system' => $event->connectionName,
                    'messaging.destination.name' => $event->job->getQueue(),
                    'messaging.operation.name' => 'process',
                    'messaging.message.id' => $event->job->getJobId(),
                    'messaging.message.job_name' => $event->job->resolveName(),
                    'messaging.message.attempts' => $event->job->attempts(),
                    // A worker invocation can be the one that paid the cold
                    // start just as easily as a web request.
                    ...ColdStart::claim(),
                ],
            );
        });

        $events->listen(JobProcessed::class, static function (JobProcessed $event) use ($tracer, &$current): void {
            $tracer->endSpan($current);
            $current = null;
            $tracer->endTrace();
        });

        // Both events fire for a final-attempt failure — the worker dispatches
        // JobFailed, then JobExceptionOccurred — so this runs twice. The second
        // pass must be a no-op rather than ending an already-closed trace.
        $fail = static function ($event) use ($tracer, &$current): void {
            if (! $current instanceof Span) {
                return;
            }

            $current->setStatus(SpanStatus::Fault);

            if (isset($event->exception)) {
                $current->recordException($event->exception);
            }

            $tracer->endSpan($current);
            $current = null;
            $tracer->endTrace();
        };

        $events->listen(JobFailed::class, $fail);
        $events->listen(JobExceptionOccurred::class, $fail);
    }

    /**
     * Resolve the producer's trace context.
     *
     * Order matters. The SQS system attribute is authoritative when present
     * because AWS services set it directly; our payload key covers dispatches
     * that never touched SQS.
     *
     * Note that on an SQS-triggered Lambda, `_X_AMZN_TRACE_ID` describes the
     * *invocation*, not the producer, and one invocation may carry messages
     * from many traces — so it is deliberately not consulted here.
     *
     * @param  array<string, mixed>  $payload
     */
    private function inboundHeader(JobProcessing $event, array $payload): TraceHeader
    {
        $sqs = $this->sqsTraceHeader($event);

        if ($sqs !== null) {
            return TraceHeader::parse($sqs);
        }

        $header = $payload[self::PAYLOAD_KEY] ?? null;

        return TraceHeader::parse(is_string($header) ? $header : null);
    }

    /**
     * The `AWSTraceHeader` SQS system attribute, if this job came from SQS.
     *
     * Bref's queue handler forwards the system-attribute bag as `Attributes`;
     * note it lives there rather than under `messageAttributes`.
     */
    private function sqsTraceHeader(JobProcessing $event): ?string
    {
        if (! method_exists($event->job, 'getSqsJob')) {
            return null;
        }

        $sqsJob = $event->job->getSqsJob();

        if (! is_array($sqsJob)) {
            return null;
        }

        $attributes = $sqsJob['Attributes'] ?? $sqsJob['attributes'] ?? null;

        if (! is_array($attributes)) {
            return null;
        }

        $header = $attributes['AWSTraceHeader'] ?? null;

        return is_string($header) && $header !== '' ? $header : null;
    }
}
