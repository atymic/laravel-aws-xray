<?php

declare(strict_types=1);

use Atymic\Xray\Emitter\MemoryEmitter;
use Atymic\Xray\Instrumentation\Instrumentation;
use Atymic\Xray\Tests\TestCase;
use Atymic\Xray\Trace\Span;
use Atymic\Xray\Tracer;

uses(TestCase::class)->in('Feature');

/**
 * Every span emitted so far.
 *
 * @return list<Span>
 */
function recordedSpans(): array
{
    return app(MemoryEmitter::class)->spans();
}

function recordedSpan(string $name): ?Span
{
    return app(MemoryEmitter::class)->named($name);
}

function emitter(): MemoryEmitter
{
    return app(MemoryEmitter::class);
}

/**
 * Enable one instrumentation for the current test, so each test declares what
 * it actually depends on.
 *
 * @param  class-string<Instrumentation>  $class
 * @param  array<string, mixed>  $options
 */
function registerInstrumentation(string $class, array $options = []): void
{
    app()->make($class)->register(app(), $options + ['enabled' => true]);
}

/**
 * Run a callback inside a trace, the way a request would.
 */
function withTrace(Closure $callback, string $name = 'root'): mixed
{
    $tracer = app(Tracer::class);
    $tracer->startTrace();

    try {
        return $tracer->trace($name, $callback);
    } finally {
        $tracer->endTrace();
    }
}
