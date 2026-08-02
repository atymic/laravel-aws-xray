<?php

declare(strict_types=1);

namespace Atymic\Xray\Lambda;

/**
 * Tracks whether the current invocation paid for a cold start.
 *
 * Deliberately a static flag — it describes the *process*, not a request, and
 * it must survive across the many requests an Octane worker serves so that only
 * the first one is attributed the initialisation cost. This is the one piece of
 * state in the package that is correctly process-scoped; everything else is
 * per-request.
 */
final class ColdStart
{
    private static bool $cold = true;

    private static ?float $bootTime = null;

    /**
     * True exactly once per execution environment. Subsequent calls report the
     * container as warm.
     */
    public static function consume(): bool
    {
        $cold = self::$cold;
        self::$cold = false;

        return $cold;
    }

    public static function isCold(): bool
    {
        return self::$cold;
    }

    /**
     * How long the runtime spent booting before serving the first request.
     *
     * Uses `LARAVEL_START` when available — Laravel's public entry points
     * define it at the very top — falling back to the request time.
     */
    public static function bootDuration(): ?float
    {
        if (self::$bootTime !== null) {
            return self::$bootTime;
        }

        $start = defined('LARAVEL_START')
            ? constant('LARAVEL_START')
            : ($_SERVER['REQUEST_TIME_FLOAT'] ?? null);

        if (! is_float($start) && ! is_int($start)) {
            return null;
        }

        return self::$bootTime = microtime(true) - (float) $start;
    }

    /**
     * Cold-start attributes for the invocation that paid the initialisation
     * cost, or none at all outside Lambda.
     *
     * Consumes the flag, so the *first* span to ask is the one credited — which
     * is why every entry point (HTTP, queue, console) must call this rather than
     * only the HTTP path. A queue worker on a cold container would otherwise
     * never record the cold start it actually paid for.
     *
     * @return array<string, scalar>
     */
    public static function claim(): array
    {
        if (! Environment::isLambda()) {
            return [];
        }

        return self::attributes(self::consume());
    }

    /**
     * @return array<string, scalar>
     */
    public static function attributes(bool $cold): array
    {
        $attributes = ['faas.coldstart' => $cold];

        if ($cold && ($duration = self::bootDuration()) !== null) {
            $attributes['faas.boot_duration_ms'] = round($duration * 1000, 2);
        }

        if (($type = Environment::initializationType()) !== null) {
            $attributes['faas.initialization_type'] = $type;
        }

        return $attributes;
    }

    /**
     * Testing seam.
     */
    public static function reset(): void
    {
        self::$cold = true;
        self::$bootTime = null;
    }
}
