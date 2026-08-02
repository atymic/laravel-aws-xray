<?php

declare(strict_types=1);

namespace Atymic\Xray\Octane;

use Atymic\Xray\Tracer;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Binds trace lifetime to the request under Octane.
 *
 * Octane keeps one booted application in memory and serves many requests
 * through it — on Bref, up to `BREF_LOOP_MAX` (250 by default) per container.
 * Anything holding trace state beyond a single request therefore bleeds: spans
 * from request N attach to request N+1, or one trace grows without bound.
 *
 * These events do fire under Bref's `OctaneHandler`, which drives the stock
 * `Laravel\Octane\Worker`. `TaskReceived` and `TickReceived` do not — they are
 * Swoole-only — and `WorkerStopping` is unreliable on Lambda, where a sandbox
 * is usually frozen and later reaped without a graceful shutdown. So the trace
 * is closed in `RequestTerminated`, which runs after the response has already
 * been sent to the client.
 */
final readonly class RequestScope
{
    public function __construct(private Tracer $tracer) {}

    public function register(Dispatcher $events): void
    {
        // String event names: the package must not require laravel/octane, and
        // referencing the classes directly would break autoloading when it is
        // absent.
        $events->listen('Laravel\Octane\Events\RequestReceived', $this->onRequestReceived(...));
        $events->listen('Laravel\Octane\Events\RequestTerminated', $this->onRequestTerminated(...));

        // A worker that dies mid-request must not carry a half-open trace into
        // whatever runs next.
        $events->listen('Laravel\Octane\Events\WorkerErrorOccurred', $this->onWorkerError(...));
    }

    public function onRequestReceived(mixed $event = null): void
    {
        // Defensive: if the previous request never terminated cleanly, discard
        // its context rather than letting it adopt this request's spans.
        $this->tracer->abandon();
    }

    public function onRequestTerminated(mixed $event = null): void
    {
        $this->tracer->endTrace();
    }

    public function onWorkerError(mixed $event = null): void
    {
        $this->tracer->endTrace();
    }

    public static function isOctane(): bool
    {
        // filter_var, not a loose comparison: `"false" == true` is true in PHP,
        // which would wire up Octane hooks for an explicit opt-out.
        return filter_var($_SERVER['LARAVEL_OCTANE'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }
}
