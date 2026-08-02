<?php

declare(strict_types=1);

namespace Atymic\Xray\Facades;

use Atymic\Xray\Trace\Span;
use Atymic\Xray\Trace\SpanKind;
use Atymic\Xray\Trace\SpanStatus;
use Atymic\Xray\Trace\TraceContext;
use Atymic\Xray\Trace\TraceHeader;
use Atymic\Xray\Tracer;
use Illuminate\Support\Facades\Facade;

/**
 * @method static TraceContext startTrace(?TraceHeader $header = null, ?string $path = null)
 * @method static TraceContext|null context()
 * @method static bool isRecording()
 * @method static Span|null startSpan(string $name, SpanKind $kind = SpanKind::Internal, array<string, mixed> $attributes = [], ?float $startTime = null)
 * @method static void endSpan(?Span $span = null, ?float $endTime = null)
 * @method static mixed trace(string $name, callable $callback, SpanKind $kind = SpanKind::Internal, array<string, mixed> $attributes = [])
 * @method static Span|null currentSpan()
 * @method static TraceHeader|null propagationHeader()
 * @method static void endTrace()
 * @method static void abandon()
 * @method static void disable()
 * @method static void enable()
 * @method static void markFailed(SpanStatus $status = SpanStatus::Fault)
 *
 * @see Tracer
 */
final class Xray extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Tracer::class;
    }
}
