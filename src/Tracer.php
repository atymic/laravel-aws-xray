<?php

declare(strict_types=1);

namespace Atymic\Xray;

use Atymic\Xray\Emitter\Emitter;
use Atymic\Xray\Octane\RequestScope;
use Atymic\Xray\Trace\IdGenerator;
use Atymic\Xray\Trace\Sampler;
use Atymic\Xray\Trace\Span;
use Atymic\Xray\Trace\SpanKind;
use Atymic\Xray\Trace\SpanStatus;
use Atymic\Xray\Trace\TraceContext;
use Atymic\Xray\Trace\TraceHeader;
use Throwable;

/**
 * The package's entry point: starts and ends traces, opens and closes spans.
 *
 * Holds the current {@see TraceContext} in a plain property that is replaced on
 * every request. It is registered as a singleton, but the request-scoped state
 * lives in the context object rather than the tracer, and
 * {@see RequestScope} swaps that object per request. This
 * is the detail that makes the package safe under Octane, where one worker
 * serves many requests: nothing accumulates in a static, and no request can see
 * another's spans.
 */
final class Tracer
{
    private ?TraceContext $context = null;

    private bool $enabled = true;

    public function __construct(
        private readonly Emitter $emitter,
        private readonly Sampler $sampler,
        private readonly IdGenerator $ids = new IdGenerator,
        /**
         * True when spans must be emitted as subsegments of a segment we do not
         * own — the case under Lambda `tracing: Active`, where the runtime
         * creates the function segment and user code cannot modify it.
         */
        private readonly bool $emitAsSubsegments = false,
    ) {}

    /**
     * Begin a trace. Any previous context is discarded, which is what keeps a
     * request that died mid-flight from leaking into the next one.
     */
    public function startTrace(?TraceHeader $header = null, ?string $path = null): TraceContext
    {
        $header ??= new TraceHeader;

        $sampled = $this->enabled && $this->sampler->shouldSample($header, $path);

        $this->context = new TraceContext(
            // Continue the inbound trace when there is one, else start our own.
            traceId: $header->traceId ?? $this->ids->traceId(),
            inboundParentId: $header->parentId,
            sampled: $sampled,
        );

        return $this->context;
    }

    public function context(): ?TraceContext
    {
        return $this->context;
    }

    public function isRecording(): bool
    {
        return $this->enabled && $this->context?->sampled === true;
    }

    /**
     * Open a span. Returns null when the request is not being traced, so
     * instrumentation can skip its work entirely rather than building spans
     * that will be thrown away.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function startSpan(
        string $name,
        SpanKind $kind = SpanKind::Internal,
        array $attributes = [],
        ?float $startTime = null,
    ): ?Span {
        if (! $this->isRecording()) {
            return null;
        }

        $context = $this->context;
        $stack = $context->stack();
        $parent = $stack->current();

        // Root only when nothing is open above us *and* we own the segment.
        // Under Active tracing Lambda owns it, so even our outermost span is a
        // subsegment hanging off the invocation.
        $isRoot = $parent === null && ! $this->emitAsSubsegments;

        $span = new Span(
            name: $name,
            traceId: $context->traceId,
            spanId: $this->ids->spanId(),
            // Nest under whatever is open; otherwise hang off the caller's
            // segment, so an upstream service's trace continues through us.
            parentSpanId: $parent === null ? $context->rootParentId() : $parent->spanId,
            kind: $kind,
            startTime: $startTime ?? microtime(true),
            isRoot: $isRoot,
        );

        $span->setAttributes($attributes);
        $stack->push($span);

        return $span;
    }

    /**
     * Close a span and record it for emission.
     */
    public function endSpan(?Span $span = null, ?float $endTime = null): void
    {
        if ($span === null || $this->context === null) {
            return;
        }

        $this->context->stack()->pop($span);
        $span->end($endTime);
        $this->context->record($span);
    }

    /**
     * Trace a callable, closing the span even when it throws.
     *
     * The safe way to instrument. The alternative — separate start and end
     * calls — leaks a span on every exception path, which under Octane means
     * the leak survives into later requests.
     *
     * @template T
     *
     * @param  callable(Span|null): T  $callback
     * @param  array<string, mixed>  $attributes
     * @return T
     */
    public function trace(
        string $name,
        callable $callback,
        SpanKind $kind = SpanKind::Internal,
        array $attributes = [],
    ): mixed {
        $span = $this->startSpan($name, $kind, $attributes);

        try {
            return $callback($span);
        } catch (Throwable $e) {
            $span?->recordException($e);

            throw $e;
        } finally {
            $this->endSpan($span);
        }
    }

    public function currentSpan(): ?Span
    {
        return $this->context?->stack()->current();
    }

    /**
     * The header to send downstream, so a receiving service continues this
     * trace rather than starting its own.
     */
    public function propagationHeader(): ?TraceHeader
    {
        return $this->context?->toHeader();
    }

    /**
     * Close the trace and hand everything to the emitter.
     *
     * Called from `RequestTerminated`, which runs after the response has been
     * sent, so transport cost never reaches the client.
     */
    public function endTrace(): void
    {
        if ($this->context === null) {
            return;
        }

        // Anything still open is instrumentation that failed to clean up.
        // Closing it here turns a leak into a slightly-wrong duration.
        foreach ($this->context->stack()->flush() as $orphan) {
            $this->context->record($orphan);
        }

        $spans = $this->context->drain();

        if ($spans !== []) {
            $this->emitter->emit($spans);
            $this->emitter->flush();
        }

        $this->context = null;
    }

    /**
     * Drop the current trace without emitting. Used when a request turns out
     * not to be worth tracing after it has started.
     */
    public function abandon(): void
    {
        $this->context = null;
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function emitter(): Emitter
    {
        return $this->emitter;
    }

    /**
     * Mark the current span as failed, e.g. from an exception handler.
     */
    public function markFailed(SpanStatus $status = SpanStatus::Fault): void
    {
        $this->currentSpan()?->setStatus($status);
    }
}
