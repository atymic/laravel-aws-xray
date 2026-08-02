<?php

declare(strict_types=1);

use Atymic\Xray\Octane\RequestScope;
use Atymic\Xray\Trace\SpanKind;
use Atymic\Xray\Tracer;

/**
 * The decisive tests for this package.
 *
 * Octane serves many requests from one booted application — on Bref up to
 * `BREF_LOOP_MAX` (250) per container. A tracer that holds state beyond a
 * request appears to work perfectly on a cold container serving one request,
 * and silently corrupts every trace after that. These tests drive many requests
 * through one tracer to catch exactly that.
 */
beforeEach(function (): void {
    $this->tracer = app(Tracer::class);
});

afterEach(function (): void {
    // These drive detection through the environment, so they must not leak into
    // whatever runs next.
    foreach (['AWS_LAMBDA_FUNCTION_NAME', 'BREF_LOOP_MAX', 'XRAY_OCTANE'] as $key) {
        putenv($key);
    }

    unset($_SERVER['LARAVEL_OCTANE'], $_SERVER['XRAY_OCTANE']);
});

it('gives every request its own trace id', function (): void {
    foreach (range(1, 250) as $i) {
        $this->tracer->startTrace();
        $this->tracer->trace("request-{$i}", fn () => null);
        $this->tracer->endTrace();
    }

    $traceIds = emitter()->traceIds();

    // 250 requests, 250 distinct traces — not one giant trace, and not 250
    // spans sharing the first request's id.
    expect($traceIds)->toHaveCount(250)
        ->and(emitter()->spans())->toHaveCount(250);
});

it('does not leak spans from one request into the next', function (): void {
    $this->tracer->startTrace();
    $first = $this->tracer->startSpan('first');
    $this->tracer->endSpan($first);
    $this->tracer->endTrace();

    $this->tracer->startTrace();
    $second = $this->tracer->startSpan('second');
    $this->tracer->endSpan($second);
    $this->tracer->endTrace();

    $spans = emitter()->spans();

    expect($spans)->toHaveCount(2)
        ->and($spans[0]->traceId)->not->toBe($spans[1]->traceId)
        // The second span must be a root, not a child of the first request's.
        ->and($spans[1]->parentSpanId)->toBeNull();
});

it('closes spans that instrumentation left open', function (): void {
    $this->tracer->startTrace();
    $this->tracer->startSpan('leaked');           // never ended
    $this->tracer->endTrace();

    expect(emitter()->spans())->toHaveCount(1)
        ->and(emitter()->first()->isEnded())->toBeTrue();
});

it('does not carry a leaked span into the following request', function (): void {
    // The failure mode this guards: a span left open by a throwing request
    // becomes the parent of everything in the next one.
    $this->tracer->startTrace();
    $this->tracer->startSpan('leaked');
    $this->tracer->endTrace();

    $this->tracer->startTrace();
    $next = $this->tracer->startSpan('next');
    $this->tracer->endSpan($next);
    $this->tracer->endTrace();

    expect($next->parentSpanId)->toBeNull()
        ->and(emitter()->traceIds())->toHaveCount(2);
});

it('discards an abandoned request rather than adopting its spans', function (): void {
    // RequestReceived calls abandon() defensively, for the case where the
    // previous request died before RequestTerminated ran.
    $this->tracer->startTrace();
    $this->tracer->startSpan('never-finished');

    $this->tracer->abandon();

    $this->tracer->startTrace();
    $clean = $this->tracer->startSpan('clean');
    $this->tracer->endSpan($clean);
    $this->tracer->endTrace();

    expect(emitter()->spans())->toHaveCount(1)
        ->and(emitter()->first()->name)->toBe('clean');
});

it('keeps memory flat across many requests', function (): void {
    // A static accumulator would show up here as unbounded growth.
    foreach (range(1, 50) as $i) {
        $this->tracer->startTrace();
        $this->tracer->trace("warmup-{$i}", fn () => null);
        $this->tracer->endTrace();
    }

    emitter()->reset();
    gc_collect_cycles();
    $baseline = memory_get_usage();

    foreach (range(1, 500) as $i) {
        $this->tracer->startTrace();
        $this->tracer->trace("request-{$i}", function () {
            $inner = app(Tracer::class)->startSpan('inner', SpanKind::Client);
            app(Tracer::class)->endSpan($inner);
        });
        $this->tracer->endTrace();
        emitter()->reset();
    }

    gc_collect_cycles();

    expect(memory_get_usage() - $baseline)->toBeLessThan(512 * 1024);
});

it('nests spans within a request but not across requests', function (): void {
    $this->tracer->startTrace();
    $outer = $this->tracer->startSpan('outer');
    $inner = $this->tracer->startSpan('inner');

    expect($inner->parentSpanId)->toBe($outer->spanId);

    $this->tracer->endSpan($inner);
    $this->tracer->endSpan($outer);
    $this->tracer->endTrace();

    $this->tracer->startTrace();
    $nextRequest = $this->tracer->startSpan('next');
    $this->tracer->endSpan($nextRequest);
    $this->tracer->endTrace();

    expect($nextRequest->parentSpanId)->toBeNull();
});

it('detects octane only for truthy values', function (mixed $value, bool $expected): void {
    // `"false" == true` is true in PHP; a loose comparison here would wire up
    // Octane hooks for an explicit opt-out.
    if ($value === null) {
        unset($_SERVER['LARAVEL_OCTANE']);
    } else {
        $_SERVER['LARAVEL_OCTANE'] = $value;
    }

    expect(RequestScope::isOctane())->toBe($expected);

    unset($_SERVER['LARAVEL_OCTANE']);
})->with([
    ['1', true],
    ['true', true],
    [true, true],
    ['0', false],
    ['false', false],
    [null, false],
]);

it('ends the trace on the octane request lifecycle', function (): void {
    $scope = new RequestScope($this->tracer);

    $this->tracer->startTrace();
    $span = $this->tracer->startSpan('handled');
    $this->tracer->endSpan($span);

    $scope->onRequestTerminated();

    expect(emitter()->spans())->toHaveCount(1)
        ->and($this->tracer->context())->toBeNull();
});

it('detects bref as octane even though LARAVEL_OCTANE is never set', function (): void {
    // Measured on a real Bref deploy: LARAVEL_OCTANE is null, yet one container
    // serves up to BREF_LOOP_MAX requests through a single booted app. Without
    // this the scope hooks never register and state bleeds between requests —
    // the exact failure this class exists to prevent.
    unset($_SERVER['LARAVEL_OCTANE']);
    putenv('AWS_LAMBDA_FUNCTION_NAME=addcal-web');
    putenv('BREF_LOOP_MAX=250');

    expect(RequestScope::isOctane())->toBeTrue();
})->skip(
    fn () => ! class_exists('Laravel\Octane\ApplicationGateway'),
    'laravel/octane not installed',
);

it('does not treat a one-shot lambda invocation as octane', function (): void {
    // No BREF_LOOP_MAX means no container reuse, so per-request hooks would be
    // pointless work on every invocation.
    unset($_SERVER['LARAVEL_OCTANE']);
    putenv('AWS_LAMBDA_FUNCTION_NAME=addcal-artisan');
    putenv('BREF_LOOP_MAX');

    expect(RequestScope::isOctane())->toBeFalse();
});

it('lets XRAY_OCTANE settle it either way', function (string $value, bool $expected): void {
    // The escape hatch for runtimes that report nothing useful about themselves.
    unset($_SERVER['LARAVEL_OCTANE']);
    putenv('AWS_LAMBDA_FUNCTION_NAME=addcal-web');
    putenv('BREF_LOOP_MAX=250');
    putenv('XRAY_OCTANE='.$value);

    expect(RequestScope::isOctane())->toBe($expected);

    putenv('XRAY_OCTANE');
})->with([
    ['true', true],
    ['1', true],
    // Must be able to force it OFF even where detection would say yes.
    ['false', false],
    ['0', false],
]);
