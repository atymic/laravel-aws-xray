<?php

declare(strict_types=1);

namespace Atymic\Xray\Trace;

use Stringable;

/**
 * The `X-Amzn-Trace-Id` header (`_X_AMZN_TRACE_ID` as a Lambda env var).
 *
 * Wire format is semicolon-separated `Key=Value` pairs:
 *
 *     Root=1-5759e988-bd862e3fe1be46a994272793;Parent=53995c3f42cd8ad8;Sampled=1
 *
 * `Sampled` is `1` (record), `0` (don't), or `?` (decision deferred to us).
 *
 * `Lineage` is appended by Lambda and other AWS services for their own
 * purposes; AWS documents that it "should not be directly used", so we neither
 * parse nor re-emit it.
 *
 * @see https://docs.aws.amazon.com/xray/latest/devguide/xray-concepts.html
 */
final readonly class TraceHeader implements Stringable
{
    public function __construct(
        public ?string $traceId = null,
        public ?string $parentId = null,
        public ?bool $sampled = null,
    ) {}

    public static function parse(?string $header): self
    {
        if ($header === null || trim($header) === '') {
            return new self;
        }

        $fields = [];

        foreach (explode(';', $header) as $pair) {
            $parts = explode('=', trim($pair), 2);

            if (count($parts) === 2) {
                $fields[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }

        $traceId = $fields['root'] ?? null;

        // A malformed Root is worse than none: it would produce traces that
        // silently never appear. Drop it and let the caller start fresh.
        if ($traceId !== null && ! IdGenerator::isValidTraceId($traceId)) {
            $traceId = null;
        }

        $parentId = $fields['parent'] ?? null;

        if ($parentId !== null && ! IdGenerator::isValidSpanId($parentId)) {
            $parentId = null;
        }

        return new self(
            traceId: $traceId,
            parentId: $parentId,
            sampled: self::parseSampled($fields['sampled'] ?? null),
        );
    }

    /**
     * `?` means the upstream deferred the decision to us, which we represent as
     * null — the same as "absent" — so the sampler decides.
     */
    private static function parseSampled(?string $value): ?bool
    {
        return match ($value) {
            '1' => true,
            '0' => false,
            default => null,
        };
    }

    public function hasTrace(): bool
    {
        return $this->traceId !== null;
    }

    public function __toString(): string
    {
        $parts = [];

        if ($this->traceId !== null) {
            $parts[] = 'Root='.$this->traceId;
        }

        if ($this->parentId !== null) {
            $parts[] = 'Parent='.$this->parentId;
        }

        if ($this->sampled !== null) {
            $parts[] = 'Sampled='.($this->sampled ? '1' : '0');
        }

        return implode(';', $parts);
    }
}
