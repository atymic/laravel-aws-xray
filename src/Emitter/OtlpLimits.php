<?php

declare(strict_types=1);

namespace Atymic\Xray\Emitter;

use Atymic\Xray\Serializer\OtlpSerializer;
use Atymic\Xray\Trace\Span;
use Psr\Log\LoggerInterface;

/**
 * Keeps an OTLP payload inside the limits the endpoint enforces.
 *
 * These matter more than they look. For span count, resource/scope size,
 * request bytes and timestamps the documentation is explicit that exceeding the
 * limit rejects the **entire API call** — so one bad span silently discards a
 * whole request's trace. (The 200 KB per-span limit is documented as rejecting
 * just the span; trimming it here anyway costs nothing and keeps one rule.)
 * Trimming the offender and splitting the batch turns a total loss into a
 * partial one.
 *
 * The limits are the traces endpoint's published values. The collector path
 * applies them too — a local collector will happily accept a payload that AWS
 * then rejects downstream, where nothing is left to log it.
 *
 * @see https://docs.aws.amazon.com/AmazonCloudWatch/latest/monitoring/CloudWatch-OTLPEndpoint.html
 */
final readonly class OtlpLimits
{
    /** Rejected above this uncompressed. Held below it with margin for the resource block. */
    public const MAX_REQUEST_BYTES = 5_000_000;

    /** Spans per request. */
    public const MAX_SPANS = 10_000;

    /** A single span above this is rejected. */
    public const MAX_SPAN_BYTES = 200_000;

    /**
     * Attribute values are trimmed to this before a span is dropped outright.
     * Well under the span limit, so trimming one runaway value is usually
     * enough to save the span.
     */
    public const MAX_ATTRIBUTE_BYTES = 8_192;

    /** Spans starting further ahead than this are rejected. */
    public const MAX_FUTURE_SECONDS = 7_200;

    /** Spans starting further back than this are rejected. */
    public const MAX_AGE_SECONDS = 1_209_600;

    public function __construct(
        private OtlpSerializer $serializer,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * Serialize spans into one or more payloads, each within every limit.
     *
     * Returns a list because a batch that exceeds the span count or byte
     * ceiling is split rather than truncated — dropping the tail would lose
     * traces without ever saying so.
     *
     * @param  list<Span>  $spans
     * @return list<array<string, mixed>>
     */
    public function payloads(array $spans): array
    {
        if ($spans === []) {
            return [];
        }

        $payloads = [];

        foreach (array_chunk($spans, self::MAX_SPANS) as $chunk) {
            foreach ($this->split($chunk) as $payload) {
                $payloads[] = $payload;
            }
        }

        return $payloads;
    }

    /**
     * Split a chunk until every payload fits the request ceiling.
     *
     * @param  list<Span>  $spans
     * @return list<array<string, mixed>>
     */
    private function split(array $spans): array
    {
        $spans = $this->withOversizedRemoved($spans);

        if ($spans === []) {
            return [];
        }

        $payload = $this->serializer->serialize($spans);

        if ($this->size($payload) <= self::MAX_REQUEST_BYTES) {
            return [$payload];
        }

        // A single span already under the span limit but over the request
        // limit cannot be split further; the span guard has already passed it,
        // so send it and let the endpoint have the final say.
        if (count($spans) === 1) {
            return [$payload];
        }

        $half = (int) ceil(count($spans) / 2);

        return [
            ...$this->split(array_slice($spans, 0, $half)),
            ...$this->split(array_slice($spans, $half)),
        ];
    }

    /**
     * Trim spans that would sink the batch, dropping only those still too large
     * after trimming.
     *
     * @param  list<Span>  $spans
     * @return list<Span>
     */
    private function withOversizedRemoved(array $spans): array
    {
        $kept = [];
        $now = microtime(true);

        foreach ($spans as $span) {
            // A timestamp outside the accepted window rejects the whole batch,
            // so a clock-skewed host would take every other span with it.
            if (! $this->withinTimeWindow($span, $now)) {
                $this->logger?->warning('xray: dropped a span outside the otlp time window', [
                    'span' => $span->name,
                    'age_seconds' => round($now - $span->startTime),
                ]);

                continue;
            }

            if ($this->isObviouslySmall($span) || $this->spanSize($span) <= self::MAX_SPAN_BYTES) {
                $kept[] = $span;

                continue;
            }

            $this->trim($span);

            if ($this->spanSize($span) <= self::MAX_SPAN_BYTES) {
                $kept[] = $span;

                continue;
            }

            // Still too large, so the bulk is spread across many events rather
            // than concentrated in one value. Tighten the per-value budget
            // until it fits — a span with shortened event detail beats no span,
            // and beats losing the whole request's trace.
            foreach ([1_024, 128, 0] as $budget) {
                $span->truncateEventAttributes($budget);

                if ($this->spanSize($span) <= self::MAX_SPAN_BYTES) {
                    break;
                }
            }

            if ($this->spanSize($span) <= self::MAX_SPAN_BYTES) {
                $this->logger?->warning('xray: trimmed event detail to fit the otlp size limit', [
                    'span' => $span->name,
                    'events' => count($span->events()),
                ]);

                $kept[] = $span;

                continue;
            }

            // Dropping one span keeps the rest of the trace; keeping it would
            // lose all of them.
            $this->logger?->warning('xray: dropped a span over the otlp size limit', [
                'span' => $span->name,
                'bytes' => $this->spanSize($span),
                'limit' => self::MAX_SPAN_BYTES,
            ]);
        }

        return $kept;
    }

    /**
     * Cut the largest values down, which is almost always what made a span
     * oversized — a long query, a stack trace, a captured body, or a pile of
     * cache events carrying unbounded keys.
     *
     * Event attributes are trimmed too: a span made oversized purely by its
     * events would otherwise be measured, trimmed to no effect, and dropped.
     */
    private function trim(Span $span): void
    {
        foreach ($span->attributes() as $key => $value) {
            if (! is_string($value) || strlen($value) <= self::MAX_ATTRIBUTE_BYTES) {
                continue;
            }

            $span->setAttribute($key, mb_strcut($value, 0, self::MAX_ATTRIBUTE_BYTES).'…[truncated]');
        }

        $span->truncateEventAttributes(self::MAX_ATTRIBUTE_BYTES);
    }

    private function withinTimeWindow(Span $span, float $now): bool
    {
        $age = $now - $span->startTime;

        return $age <= self::MAX_AGE_SECONDS && $age >= -self::MAX_FUTURE_SECONDS;
    }

    /**
     * Whether a span is comfortably small enough to skip the exact measurement.
     *
     * Serializing every span twice would double the cost of the common case to
     * catch something that essentially never happens. Summing raw lengths is far
     * cheaper and must *under*-estimate the serialized size, so anything this
     * clears by a wide margin is genuinely safe.
     *
     * Every payload the serializer writes has to be counted here. Events were
     * once counted by name alone, which let a span carrying large event
     * attributes — an unbounded cache key, a view name — skip measurement and
     * ship far over the limit, taking the whole request's trace with it.
     */
    private function isObviouslySmall(Span $span): bool
    {
        $bytes = strlen($span->name);

        $bytes += $this->attributeBytes($span->attributes());

        foreach ($span->exceptions() as $exception) {
            $bytes += strlen($exception['type'])
                + strlen($exception['message'])
                + 128 * (count($exception['stack']) + 1);
        }

        foreach ($span->events() as $event) {
            $bytes += strlen($event['name']) + 64 + $this->attributeBytes($event['attributes']);
        }

        // Quarter of the limit: JSON framing and escaping inflate the raw
        // total, but never fourfold.
        return $bytes < intdiv(self::MAX_SPAN_BYTES, 4);
    }

    /**
     * @param  array<string, scalar|null>  $attributes
     */
    private function attributeBytes(array $attributes): int
    {
        $bytes = 0;

        foreach ($attributes as $key => $value) {
            $bytes += strlen($key) + (is_string($value) ? strlen($value) : 16);
        }

        return $bytes;
    }

    private function spanSize(Span $span): int
    {
        // Measured as serialized, since that is what the limit applies to.
        $payload = $this->serializer->serialize([$span]);

        $spans = $payload['resourceSpans'][0]['scopeSpans'][0]['spans'] ?? [];

        return $spans === [] ? 0 : $this->size($spans[0]);
    }

    /**
     * @param  array<string|int, mixed>  $value
     */
    private function size(array $value): int
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);

        return $encoded === false ? PHP_INT_MAX : strlen($encoded);
    }
}
