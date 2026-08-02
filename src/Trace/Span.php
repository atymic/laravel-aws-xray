<?php

declare(strict_types=1);

namespace Atymic\Xray\Trace;

use Throwable;

/**
 * A single unit of work.
 *
 * Deliberately emitter-agnostic: attributes are set once using OpenTelemetry
 * semantic conventions, and each serializer translates them into its own shape
 * (see AttributeMapper for the X-Ray side). Instrumentation never knows which
 * emitter is active.
 *
 * Mutable by design — a span is opened, annotated as work proceeds, then
 * closed. It is a short-lived per-request object, never shared across requests.
 */
final class Span
{
    private ?float $endTime = null;

    private SpanStatus $status = SpanStatus::Unset;

    /** @var array<string, scalar|null> */
    private array $attributes = [];

    /** @var list<array{name: string, time: float, attributes: array<string, scalar|null>}> */
    private array $events = [];

    /** @var list<array{type: string, message: string, stack: list<array{path: string, line: int, label: string}>}> */
    private array $exceptions = [];

    public function __construct(
        /**
         * Kept low-cardinality: it names a node on the X-Ray service map, so a
         * value containing ids would draw one node per request.
         *
         * Not readonly because the HTTP root span must be opened before the
         * router has matched — see {@see rename()}.
         */
        public string $name,
        public readonly string $traceId,
        public readonly string $spanId,
        public readonly ?string $parentSpanId,
        public readonly SpanKind $kind,
        public readonly float $startTime,
        /**
         * True when this span has no parent inside this process and should be
         * serialized as a full X-Ray segment. False when it must be emitted as
         * `type: subsegment` — which is what Lambda requires under
         * `tracing: Active`, where the function segment is created by the
         * runtime and is read-only.
         */
        public readonly bool $isRoot = false,
    ) {}

    /**
     * Rename a span whose final name is not knowable when it opens — in
     * practice the HTTP root span, which must start before routing has
     * resolved the pattern it should be named after.
     */
    public function rename(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function setAttribute(string $key, mixed $value): self
    {
        if ($value === null || is_scalar($value)) {
            $this->attributes[$key] = $value;
        }

        return $this;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function setAttributes(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }

        return $this;
    }

    public function getAttribute(string $key): string|int|float|bool|null
    {
        return $this->attributes[$key] ?? null;
    }

    /** @return array<string, scalar|null> */
    public function attributes(): array
    {
        return $this->attributes;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function addEvent(string $name, array $attributes = [], ?float $time = null): self
    {
        $filtered = [];

        foreach ($attributes as $key => $value) {
            if ($value === null || is_scalar($value)) {
                $filtered[$key] = $value;
            }
        }

        $this->events[] = [
            'name' => $name,
            'time' => $time ?? microtime(true),
            'attributes' => $filtered,
        ];

        return $this;
    }

    /** @return list<array{name: string, time: float, attributes: array<string, scalar|null>}> */
    public function events(): array
    {
        return $this->events;
    }

    /**
     * Cut oversized event attribute values down to `$limit` bytes.
     *
     * Exists for the emitter size guards: a span carrying many large event
     * attributes — an unbounded cache key, a long view name — can exceed the
     * per-span wire limit, and trimming it is what keeps the rest of the trace
     * deliverable. Truncated values are marked so the loss is visible.
     *
     * @phpstan-impure Mutates the span, so a size measured before this call
     *                 does not hold after it.
     */
    public function truncateEventAttributes(int $limit, string $suffix = '…[truncated]'): self
    {
        foreach ($this->events as $i => $event) {
            foreach ($event['attributes'] as $key => $value) {
                if (! is_string($value) || strlen($value) <= $limit) {
                    continue;
                }

                $this->events[$i]['attributes'][$key] = mb_strcut($value, 0, $limit).$suffix;
            }
        }

        return $this;
    }

    /**
     * Record an exception and mark the span as faulted.
     *
     * @param  int  $stackLimit  Frames to capture. Stack traces are the single
     *                           largest contributor to span size, and
     *                           Transaction Search bills per byte, so this is
     *                           deliberately shallow by default.
     */
    public function recordException(Throwable $e, int $stackLimit = 10): self
    {
        $stack = [];

        foreach (array_slice($e->getTrace(), 0, $stackLimit) as $frame) {
            $stack[] = [
                'path' => $frame['file'] ?? 'unknown',
                'line' => $frame['line'] ?? 0,
                'label' => $frame['function'] ?? 'unknown',
            ];
        }

        $this->exceptions[] = [
            'type' => $e::class,
            'message' => $e->getMessage(),
            'stack' => $stack,
        ];

        return $this->setStatus(SpanStatus::Fault);
    }

    /** @return list<array{type: string, message: string, stack: list<array{path: string, line: int, label: string}>}> */
    public function exceptions(): array
    {
        return $this->exceptions;
    }

    public function setStatus(SpanStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function status(): SpanStatus
    {
        return $this->status;
    }

    public function end(?float $time = null): self
    {
        // First close wins: instrumentation may defensively end a span that a
        // stack unwind has already closed, and re-closing would misreport
        // duration.
        $this->endTime ??= $time ?? microtime(true);

        return $this;
    }

    public function isEnded(): bool
    {
        return $this->endTime !== null;
    }

    public function endTime(): ?float
    {
        return $this->endTime;
    }

    public function duration(): ?float
    {
        return $this->endTime === null ? null : $this->endTime - $this->startTime;
    }
}
