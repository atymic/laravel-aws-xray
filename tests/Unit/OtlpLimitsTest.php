<?php

declare(strict_types=1);

use Atymic\Xray\Emitter\OtlpLimits;
use Atymic\Xray\Serializer\OtlpSerializer;
use Atymic\Xray\Trace\IdGenerator;
use Atymic\Xray\Trace\Span;
use Atymic\Xray\Trace\SpanKind;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

function limits(?LoggerInterface $logger = null): OtlpLimits
{
    return new OtlpLimits(new OtlpSerializer('test-service'), $logger);
}

function limitsSpan(string $name = 'span', array $attributes = [], ?float $startTime = null): Span
{
    $ids = new IdGenerator;

    $span = new Span(
        name: $name,
        traceId: $ids->traceId(),
        spanId: $ids->spanId(),
        parentSpanId: null,
        kind: SpanKind::Internal,
        startTime: $startTime ?? microtime(true),
        isRoot: true,
    );

    foreach ($attributes as $key => $value) {
        $span->setAttribute($key, $value);
    }

    return $span->end();
}

/**
 * @return list<array<string, mixed>>
 */
function spansIn(array $payload): array
{
    return $payload['resourceSpans'][0]['scopeSpans'][0]['spans'] ?? [];
}

/**
 * Median seconds per iteration, which is stable enough for a ratio assertion
 * on a shared CI machine.
 */
function benchmark(Closure $callback, int $iterations = 50): float
{
    $samples = [];

    for ($i = 0; $i < $iterations; $i++) {
        $start = hrtime(true);
        $callback();
        $samples[] = hrtime(true) - $start;
    }

    sort($samples);

    return $samples[intdiv($iterations, 2)] / 1_000_000_000;
}

it('passes an ordinary batch through as one payload', function (): void {
    $payloads = limits()->payloads([limitsSpan('a'), limitsSpan('b')]);

    expect($payloads)->toHaveCount(1)
        ->and(spansIn($payloads[0]))->toHaveCount(2);
});

it('returns nothing for no spans', function (): void {
    expect(limits()->payloads([]))->toBe([]);
});

it('does not measure ordinary spans exactly', function (): void {
    // The guards run on the request path for the direct OTLP emitter, so the
    // common case must not pay for serializing every span twice. Asserted as a
    // time budget because the fast path is a performance property, not a
    // structural one.
    $spans = array_map(
        static fn (int $i): Span => limitsSpan("span-$i", ['db.query.text' => 'select 1']),
        range(1, 40),
    );

    $serializer = new OtlpSerializer('test-service');
    $limits = new OtlpLimits($serializer);

    $baseline = benchmark(static fn () => $serializer->serialize($spans));
    $guarded = benchmark(static fn () => $limits->payloads($spans));

    // Exact measurement of every span would be many times the baseline.
    expect($guarded)->toBeLessThan($baseline * 3);
});

it('trims an oversized attribute rather than dropping the span', function (): void {
    // A long query or captured body is the usual cause, and the span is still
    // worth having once the offending value is cut down.
    $span = limitsSpan('big', ['db.query.text' => str_repeat('x', 300_000)]);

    $payloads = limits()->payloads([$span]);

    expect($payloads)->toHaveCount(1)
        ->and(spansIn($payloads[0]))->toHaveCount(1)
        ->and($span->attributes()['db.query.text'])->toEndWith('…[truncated]')
        ->and(strlen($span->attributes()['db.query.text']))
        ->toBeLessThan(OtlpLimits::MAX_SPAN_BYTES);
});

it('keeps the rest of the trace when one span cannot be saved', function (): void {
    // The endpoint rejects the whole request over a limit, so dropping the one
    // offender is what preserves the others.
    $unsalvageable = limitsSpan('huge', array_map(
        static fn (int $i): string => str_repeat('y', 7_000),
        array_flip(array_map(static fn (int $i): string => "attr.$i", range(1, 60))),
    ));

    $payloads = limits()->payloads([limitsSpan('fine'), $unsalvageable]);

    $names = array_column(spansIn($payloads[0]), 'name');

    expect($names)->toContain('fine')
        ->and($names)->not->toContain('huge');
});

it('logs a dropped span so the loss is not silent', function (): void {
    $logger = new class extends AbstractLogger
    {
        public array $messages = [];

        public function log($level, $message, array $context = []): void
        {
            $this->messages[] = $message;
        }
    };

    $unsalvageable = limitsSpan('huge', array_map(
        static fn (int $i): string => str_repeat('y', 7_000),
        array_flip(array_map(static fn (int $i): string => "attr.$i", range(1, 60))),
    ));

    limits($logger)->payloads([$unsalvageable]);

    expect($logger->messages)->toContain('xray: dropped a span over the otlp size limit');
});

it('measures event attributes, not just event names', function (): void {
    // Regression: the fast-path estimator once counted only an event's name, so
    // a span carrying large event attributes skipped measurement and shipped
    // far over the limit — taking the whole request's trace with it.
    $span = limitsSpan('cache-heavy');

    for ($i = 0; $i < 100; $i++) {
        $span->addEvent('cache.get', ['cache.key' => str_repeat('k', 5_000)]);
    }

    $payloads = limits()->payloads([$span]);

    foreach (spansIn($payloads[0] ?? []) as $emitted) {
        expect(strlen(json_encode($emitted)))->toBeLessThanOrEqual(OtlpLimits::MAX_SPAN_BYTES);
    }
});

it('trims event detail rather than dropping a span made oversized by events', function (): void {
    // Cache and view instrumentation write span events with unbounded keys, so
    // a span shortened to fit beats losing it and beats losing the trace.
    $span = limitsSpan('cache-heavy');

    for ($i = 0; $i < 100; $i++) {
        $span->addEvent('cache.get', ['cache.key' => str_repeat('k', 5_000)]);
    }

    $payloads = limits()->payloads([$span]);

    expect(spansIn($payloads[0]))->toHaveCount(1);
});

it('splits a batch that exceeds the span count', function (): void {
    $spans = array_map(static fn (int $i): Span => limitsSpan("span-$i"), range(1, 10_005));

    $payloads = limits()->payloads($spans);

    expect($payloads)->toHaveCount(2)
        ->and(spansIn($payloads[0]))->toHaveCount(OtlpLimits::MAX_SPANS)
        ->and(spansIn($payloads[1]))->toHaveCount(5);
})->skip(PHP_INT_SIZE < 8, 'needs 64-bit');

it('splits a batch that exceeds the request byte ceiling', function (): void {
    // Each span is comfortably under the per-span limit but together they blow
    // the request limit, so the batch has to be divided rather than dropped.
    $spans = array_map(
        static fn (int $i): Span => limitsSpan("span-$i", ['payload' => str_repeat('z', 100_000)]),
        range(1, 80),
    );

    $payloads = limits()->payloads($spans);

    $total = array_sum(array_map(static fn (array $p): int => count(spansIn($p)), $payloads));

    expect(count($payloads))->toBeGreaterThan(1)
        // Splitting must not lose spans.
        ->and($total)->toBe(80);

    foreach ($payloads as $payload) {
        expect(strlen(json_encode($payload)))->toBeLessThanOrEqual(OtlpLimits::MAX_REQUEST_BYTES);
    }
});

it('drops a span older than the accepted window', function (): void {
    // A backdated or clock-skewed span would otherwise reject the whole batch.
    $stale = limitsSpan('stale', startTime: microtime(true) - OtlpLimits::MAX_AGE_SECONDS - 60);

    $payloads = limits()->payloads([limitsSpan('fresh'), $stale]);

    expect(array_column(spansIn($payloads[0]), 'name'))->toBe(['fresh']);
});

it('drops a span dated too far in the future', function (): void {
    $ahead = limitsSpan('ahead', startTime: microtime(true) + OtlpLimits::MAX_FUTURE_SECONDS + 60);

    $payloads = limits()->payloads([limitsSpan('now'), $ahead]);

    expect(array_column(spansIn($payloads[0]), 'name'))->toBe(['now']);
});

it('accepts a span at the edge of the time window', function (): void {
    $edge = limitsSpan('edge', startTime: microtime(true) - OtlpLimits::MAX_AGE_SECONDS + 3_600);

    expect(spansIn(limits()->payloads([$edge])[0]))->toHaveCount(1);
});
