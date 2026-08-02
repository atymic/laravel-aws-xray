<?php

declare(strict_types=1);

namespace Atymic\Xray\Trace;

use Atymic\Xray\Octane\RequestScope;

/**
 * Per-request trace state: the trace id, the inbound parent, and the sampling
 * decision.
 *
 * One of these exists per request and is discarded when the request ends. It is
 * never a singleton — under Octane a worker serves up to 250 requests, and
 * holding this beyond a request is the defect that makes most Laravel X-Ray
 * packages emit one enormous trace or silently drop segments.
 *
 * @see RequestScope
 */
final class TraceContext
{
    /** @var list<Span> */
    private array $completed = [];

    public function __construct(
        public readonly string $traceId,
        public readonly ?string $inboundParentId,
        public readonly bool $sampled,
        private readonly SpanStack $stack = new SpanStack,
    ) {}

    public function stack(): SpanStack
    {
        return $this->stack;
    }

    /**
     * The id a new root span should hang off: the inbound `Parent` when one
     * arrived, otherwise nothing.
     */
    public function rootParentId(): ?string
    {
        return $this->inboundParentId;
    }

    public function record(Span $span): void
    {
        // Unsampled requests build no spans at all, but guard anyway so a
        // stray record() can never grow memory across an Octane worker's life.
        if ($this->sampled) {
            $this->completed[] = $span;
        }
    }

    /** @return list<Span> */
    public function completed(): array
    {
        return $this->completed;
    }

    public function hasCompleted(): bool
    {
        return $this->completed !== [];
    }

    /**
     * Hand over everything recorded so far and reset, so a flush can never
     * emit the same span twice.
     *
     * @return list<Span>
     */
    public function drain(): array
    {
        $spans = $this->completed;
        $this->completed = [];

        return $spans;
    }

    public function toHeader(?string $parentId = null): TraceHeader
    {
        return new TraceHeader(
            traceId: $this->traceId,
            parentId: $parentId ?? $this->stack->currentId(),
            sampled: $this->sampled,
        );
    }
}
