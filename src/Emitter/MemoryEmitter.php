<?php

declare(strict_types=1);

namespace Atymic\Xray\Emitter;

use Atymic\Xray\Trace\Span;

/**
 * Keeps spans in memory so tests can assert on them.
 *
 * Also useful in an application's own test suite: set `xray.emitter` to
 * `memory` and assert that the spans you expect were produced.
 */
final class MemoryEmitter implements Emitter
{
    /** @var list<Span> */
    private array $spans = [];

    private int $flushes = 0;

    public function emit(array $spans): void
    {
        foreach ($spans as $span) {
            $this->spans[] = $span;
        }
    }

    public function flush(): void
    {
        $this->flushes++;
    }

    /** @return list<Span> */
    public function spans(): array
    {
        return $this->spans;
    }

    public function first(): ?Span
    {
        return $this->spans[0] ?? null;
    }

    public function last(): ?Span
    {
        return $this->spans === [] ? null : $this->spans[array_key_last($this->spans)];
    }

    public function named(string $name): ?Span
    {
        foreach ($this->spans as $span) {
            if ($span->name === $name) {
                return $span;
            }
        }

        return null;
    }

    public function count(): int
    {
        return count($this->spans);
    }

    public function flushCount(): int
    {
        return $this->flushes;
    }

    /**
     * Distinct trace ids seen. The assertion that matters under Octane: one
     * trace id per request, never one shared across many.
     *
     * @return list<string>
     */
    public function traceIds(): array
    {
        return array_values(array_unique(array_map(
            static fn (Span $span): string => $span->traceId,
            $this->spans,
        )));
    }

    public function reset(): void
    {
        $this->spans = [];
        $this->flushes = 0;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function requiresTracingMode(): ?string
    {
        return null;
    }
}
