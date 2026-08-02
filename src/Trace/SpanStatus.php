<?php

declare(strict_types=1);

namespace Atymic\Xray\Trace;

/**
 * Outcome of a span.
 *
 * X-Ray splits failure into three orthogonal booleans (`error` for 4xx,
 * `fault` for 5xx, `throttle` for 429); OTLP has a single status enum. We keep
 * the richer X-Ray shape internally because it cannot be recovered from the
 * OTLP one, and collapse it when serializing to OTLP.
 */
enum SpanStatus: string
{
    case Unset = 'unset';
    case Ok = 'ok';

    /** Client error — 4xx. */
    case Error = 'error';

    /** Server error — 5xx or an unhandled exception. */
    case Fault = 'fault';

    /** Rate limited — 429. */
    case Throttle = 'throttle';

    public static function fromHttpStatus(int $status): self
    {
        return match (true) {
            $status === 429 => self::Throttle,
            $status >= 500 => self::Fault,
            $status >= 400 => self::Error,
            default => self::Ok,
        };
    }

    public function isFailure(): bool
    {
        return match ($this) {
            self::Error, self::Fault, self::Throttle => true,
            self::Unset, self::Ok => false,
        };
    }

    /**
     * OTLP status code: 0 unset, 1 ok, 2 error.
     */
    public function toOtlp(): int
    {
        return match ($this) {
            self::Unset => 0,
            self::Ok => 1,
            self::Error, self::Fault, self::Throttle => 2,
        };
    }
}
