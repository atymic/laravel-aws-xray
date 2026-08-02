<?php

declare(strict_types=1);

namespace Atymic\Xray\Trace;

/**
 * The stack of currently-open spans.
 *
 * A stack, not a name-keyed map. Keying open spans by name — as the prior art
 * in this space does — means two concurrent spans with the same name clobber
 * each other, and nesting has to be inferred by walking children looking for
 * something still open. A stack makes parentage explicit and makes
 * same-name siblings harmless.
 */
final class SpanStack
{
    /** @var list<Span> */
    private array $spans = [];

    public function push(Span $span): void
    {
        $this->spans[] = $span;
    }

    /**
     * Close the innermost open span.
     *
     * Pops to `$span` when given one, so an unwind caused by an exception
     * cannot leave orphans wedged on the stack — every span opened inside the
     * failed scope is closed too.
     */
    public function pop(?Span $span = null): ?Span
    {
        if ($this->spans === []) {
            return null;
        }

        if ($span === null) {
            return array_pop($this->spans);
        }

        $index = null;

        foreach ($this->spans as $i => $candidate) {
            if ($candidate === $span) {
                $index = $i;
                break;
            }
        }

        // Already popped, or never ours. Either way there is nothing to unwind.
        if ($index === null) {
            return null;
        }

        $orphans = array_splice($this->spans, $index);

        foreach ($orphans as $orphan) {
            $orphan->end();
        }

        return $span;
    }

    public function current(): ?Span
    {
        return $this->spans === [] ? null : $this->spans[array_key_last($this->spans)];
    }

    public function currentId(): ?string
    {
        return $this->current()?->spanId;
    }

    public function isEmpty(): bool
    {
        return $this->spans === [];
    }

    public function depth(): int
    {
        return count($this->spans);
    }

    /**
     * Close every open span, innermost first.
     *
     * The safety net for instrumentation that opened a span and never closed
     * it. Called at request end so a leak becomes a slightly-wrong duration
     * rather than a span that bleeds into the next request.
     *
     * @return list<Span>
     */
    public function flush(): array
    {
        $spans = array_reverse($this->spans);
        $this->spans = [];

        foreach ($spans as $span) {
            $span->end();
        }

        return $spans;
    }
}
