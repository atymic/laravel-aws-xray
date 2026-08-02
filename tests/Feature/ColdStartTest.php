<?php

declare(strict_types=1);

use Atymic\Xray\Instrumentation\ConsoleInstrumentation;
use Atymic\Xray\Instrumentation\QueueInstrumentation;
use Atymic\Xray\Lambda\ColdStart;
use Atymic\Xray\Tests\Support\TestJob;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

beforeEach(function (): void {
    ColdStart::reset();
    putenv('AWS_LAMBDA_FUNCTION_NAME=addcal-test');
});

afterEach(function (): void {
    putenv('AWS_LAMBDA_FUNCTION_NAME');
    ColdStart::reset();
});

/**
 * The queue events accept any job object, so this covers only what the
 * instrumentation actually reads.
 */
function fakeSqsJob(string $id = 'job-1'): object
{
    return new class($id)
    {
        public function __construct(private string $id) {}

        public function payload(): array
        {
            return [];
        }

        public function getQueue(): string
        {
            return 'emails';
        }

        public function getJobId(): string
        {
            return $this->id;
        }

        public function resolveName(): string
        {
            return TestJob::class;
        }

        public function attempts(): int
        {
            return 1;
        }
    };
}

it('attributes the cold start to a queue job on a cold container', function (): void {
    // The regression that motivated this package is a cold start; a worker
    // invocation pays it just as a web request does.
    registerInstrumentation(QueueInstrumentation::class);

    $job = fakeSqsJob();

    event(new JobProcessing('sqs', $job));
    event(new JobProcessed('sqs', $job));

    expect(recordedSpan('process emails')->attributes())
        ->toHaveKey('faas.coldstart', true);
});

it('marks only the first job in a container as cold', function (): void {
    registerInstrumentation(QueueInstrumentation::class);

    foreach (['job-1', 'job-2'] as $id) {
        $job = fakeSqsJob($id);
        event(new JobProcessing('sqs', $job));
        event(new JobProcessed('sqs', $job));
    }

    $spans = collect(recordedSpans())->where('name', 'process emails')->values();

    expect($spans)->toHaveCount(2)
        ->and($spans[0]->attributes())->toHaveKey('faas.coldstart', true)
        ->and($spans[1]->attributes())->toHaveKey('faas.coldstart', false);
});

it('attributes the cold start to an artisan command', function (): void {
    registerInstrumentation(ConsoleInstrumentation::class, ['commands' => ['migrate']]);

    $input = new ArrayInput([]);
    $output = new NullOutput;

    event(new CommandStarting('migrate', $input, $output));
    event(new CommandFinished('migrate', $input, $output, 0));

    expect(recordedSpan('artisan migrate')->attributes())
        ->toHaveKey('faas.coldstart', true);
});

it('records nothing about cold starts outside lambda', function (): void {
    // Off Lambda the concept does not apply, and the attribute would be noise
    // on every span.
    putenv('AWS_LAMBDA_FUNCTION_NAME');
    ColdStart::reset();

    registerInstrumentation(QueueInstrumentation::class);

    $job = fakeSqsJob();
    event(new JobProcessing('sqs', $job));
    event(new JobProcessed('sqs', $job));

    expect(recordedSpan('process emails')->attributes())
        ->not->toHaveKey('faas.coldstart');
});

it('reports a boot duration alongside a cold start', function (): void {
    expect(ColdStart::claim())
        ->toHaveKey('faas.coldstart', true)
        ->and(ColdStart::claim())->toHaveKey('faas.coldstart', false);
});
