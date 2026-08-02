<?php

declare(strict_types=1);

use Atymic\Xray\Instrumentation\QueueInstrumentation;
use Atymic\Xray\Tests\Support\TestJob;
use Atymic\Xray\Trace\SpanKind;
use Atymic\Xray\Trace\SpanStatus;
use Atymic\Xray\Tracer;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    TestJob::reset();
    registerInstrumentation(QueueInstrumentation::class);
});

it('records a producer span when a job is dispatched inside a trace', function (): void {
    // Driven by the event rather than a real queue: the sync driver never fires
    // JobQueued, and Queue::fake() replaces the manager so it does not either.
    withTrace(function (): void {
        event(new JobQueued(
            connectionName: 'sqs',
            queue: 'emails',
            id: 'job-123',
            job: new TestJob,
            payload: '{}',
            delay: null,
        ));
    });

    $span = recordedSpan('send emails');

    expect($span)->not->toBeNull()
        ->and($span->kind)->toBe(SpanKind::Producer)
        ->and($span->attributes())->toMatchArray([
            'messaging.operation.name' => 'send',
            'messaging.destination.name' => 'emails',
            'messaging.message.id' => 'job-123',
            'messaging.message.job_name' => TestJob::class,
        ]);
});

it('falls back to the default queue name', function (): void {
    withTrace(function (): void {
        event(new JobQueued('sync', null, 'job-1', new TestJob, '{}', null));
    });

    expect(recordedSpan('send default'))->not->toBeNull();
});

it('does not record a producer span outside a trace', function (): void {
    Queue::fake();

    TestJob::dispatch();

    expect(emitter()->spans())->toBeEmpty();
});

it('exposes a propagation header while tracing', function (): void {
    // This is the value injected into every job payload, and the reason work
    // done later still joins the request that scheduled it.
    $header = null;

    withTrace(function () use (&$header): void {
        $header = (string) app(Tracer::class)->propagationHeader();
    });

    expect($header)->toStartWith('Root=1-')
        ->and($header)->toContain('Sampled=1');
});

it('continues the producer trace on the consumer side', function (): void {
    // The whole point of queue instrumentation: work done later still shows up
    // under the request that scheduled it.
    config()->set('queue.default', 'sync');

    $producerTraceId = null;

    withTrace(function () use (&$producerTraceId): void {
        $producerTraceId = app(Tracer::class)->context()->traceId;
        TestJob::dispatch();
    });

    expect(TestJob::$observedTraceId)->toBe($producerTraceId);
});

it('records a consumer span for a processed job', function (): void {
    config()->set('queue.default', 'sync');

    withTrace(fn () => TestJob::dispatch());

    $span = recordedSpan('process sync');

    expect($span)->not->toBeNull()
        ->and($span->kind)->toBe(SpanKind::Consumer)
        ->and($span->attributes())->toMatchArray([
            'messaging.operation.name' => 'process',
        ]);
});

it('marks a failed job as faulted', function (): void {
    config()->set('queue.default', 'sync');
    TestJob::$shouldFail = true;

    try {
        withTrace(fn () => TestJob::dispatch());
    } catch (Throwable) {
        // The sync driver rethrows; the span assertions are the point.
    }

    $span = recordedSpan('process sync');

    expect($span)->not->toBeNull()
        ->and($span->status())->toBe(SpanStatus::Fault);
});

it('prefers the SQS system attribute over the payload', function (): void {
    // On SQS the AWSTraceHeader attribute is what AWS services themselves set,
    // so it is authoritative when both are present.
    $instrumentation = new QueueInstrumentation;

    $method = new ReflectionMethod($instrumentation, 'sqsTraceHeader');

    $job = new class
    {
        public function getSqsJob(): array
        {
            return [
                'Attributes' => [
                    'AWSTraceHeader' => 'Root=1-5759e988-bd862e3fe1be46a994272793;Parent=53995c3f42cd8ad8;Sampled=1',
                ],
            ];
        }
    };

    $event = new JobProcessing('sqs', $job);

    expect($method->invoke($instrumentation, $event))
        ->toBe('Root=1-5759e988-bd862e3fe1be46a994272793;Parent=53995c3f42cd8ad8;Sampled=1');
});

it('reads the SQS attribute bag under either casing', function (string $key): void {
    // Bref forwards the bag as `attributes`; the AWS SDK uses `Attributes`.
    $instrumentation = new QueueInstrumentation;
    $method = new ReflectionMethod($instrumentation, 'sqsTraceHeader');

    $job = new class($key)
    {
        public function __construct(private string $key) {}

        public function getSqsJob(): array
        {
            return [$this->key => ['AWSTraceHeader' => 'Root=1-5759e988-bd862e3fe1be46a994272793']];
        }
    };

    expect($method->invoke($instrumentation, new JobProcessing('sqs', $job)))
        ->toBe('Root=1-5759e988-bd862e3fe1be46a994272793');
})->with(['Attributes', 'attributes']);

it('tolerates an SQS job with no trace attribute', function (): void {
    // AWS only populates AWSTraceHeader when the producer set it, so absence is
    // the normal case, not an error.
    $instrumentation = new QueueInstrumentation;
    $method = new ReflectionMethod($instrumentation, 'sqsTraceHeader');

    $job = new class
    {
        public function getSqsJob(): array
        {
            return ['Attributes' => []];
        }
    };

    expect($method->invoke($instrumentation, new JobProcessing('sqs', $job)))->toBeNull();
});

it('gives each job its own trace', function (): void {
    // One Lambda invocation can deliver up to ten SQS messages from ten
    // different producers, so context is resolved per job, never per invocation.
    config()->set('queue.default', 'sync');

    TestJob::dispatch();
    $first = TestJob::$observedTraceId;

    TestJob::reset();

    TestJob::dispatch();
    $second = TestJob::$observedTraceId;

    expect($first)->not->toBeNull()
        ->and($second)->not->toBeNull()
        ->and($first)->not->toBe($second);
});
