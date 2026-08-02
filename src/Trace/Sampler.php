<?php

declare(strict_types=1);

namespace Atymic\Xray\Trace;

use Illuminate\Support\Str;

/**
 * Decides whether a request is traced.
 *
 * Only consulted when we own the decision — that is, under the collector and
 * direct OTLP emitters. Under the UDP emitter Lambda has already decided
 * (1 req/sec plus 5% of the remainder, not configurable), and its decision
 * arrives in the trace header.
 *
 * An inbound decision always wins, so a sampled upstream trace stays intact
 * across service boundaries rather than losing its middle.
 */
final readonly class Sampler
{
    /**
     * @param  float  $rate  0.0–1.0
     * @param  list<array{path: string, rate: float}>  $rules  First match wins.
     */
    public function __construct(
        private float $rate = 1.0,
        private array $rules = [],
    ) {}

    public function shouldSample(?TraceHeader $header = null, ?string $path = null): bool
    {
        // Respect an upstream decision, including an explicit "no".
        if ($header?->sampled !== null) {
            return $header->sampled;
        }

        $rate = $this->rateFor($path);

        if ($rate >= 1.0) {
            return true;
        }

        if ($rate <= 0.0) {
            return false;
        }

        return (random_int(0, PHP_INT_MAX) / PHP_INT_MAX) < $rate;
    }

    private function rateFor(?string $path): float
    {
        if ($path === null) {
            return $this->rate;
        }

        $path = '/'.ltrim($path, '/');

        foreach ($this->rules as $rule) {
            if (Str::is($rule['path'], $path)) {
                return $rule['rate'];
            }
        }

        return $this->rate;
    }
}
