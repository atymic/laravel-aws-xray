<?php

declare(strict_types=1);

namespace Atymic\Xray\Octane;

use Atymic\Xray\Lambda\Environment;
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

    /**
     * Whether one booted application is serving many requests.
     *
     * `XRAY_OCTANE` settles it either way when set — the only reliable answer
     * on a runtime that reports nothing useful about itself.
     *
     * Otherwise: `LARAVEL_OCTANE` is what `octane:start` sets, but **Bref never
     * sets it**, and Bref is precisely where this matters — one container serves
     * up to `BREF_LOOP_MAX` (250) requests through a single booted app. Falling
     * back to the Octane gateway being in play catches that. Deliberately not
     * `app()->bound(...)` or `class_exists(Worker::class)`: both are true merely
     * because laravel/octane is installed, including under a plain CLI run.
     */
    public static function isOctane(): bool
    {
        // getenv() returns false when unset, so an empty result means "not set"
        // rather than "set to something falsy".
        $forced = $_SERVER['XRAY_OCTANE'] ?? getenv('XRAY_OCTANE');

        if ($forced !== false && $forced !== '') {
            // filter_var, not a loose comparison: `"false" == true` is true in
            // PHP, which would wire up the hooks for an explicit opt-out.
            return filter_var($forced, FILTER_VALIDATE_BOOLEAN);
        }

        if (filter_var($_SERVER['LARAVEL_OCTANE'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }

        // Bref drives Octane's stock Worker without setting LARAVEL_OCTANE, but
        // still routes through ApplicationGateway, which dispatches these.
        return self::octaneGatewayIsDriving();
    }

    /**
     * Whether Bref is serving HTTP through Octane's gateway.
     *
     * `BREF_LOOP_MAX` is what makes one container serve many requests, so its
     * presence alongside a loadable gateway is the condition this class exists
     * for. Scoped to Lambda deliberately — this is a Bref-shaped gap, and a
     * broader guess would risk registering hooks under a plain CLI process.
     *
     * Anything else that reuses a container should set `XRAY_OCTANE=true`.
     */
    private static function octaneGatewayIsDriving(): bool
    {
        return Environment::isLambda()
            && class_exists('Laravel\Octane\ApplicationGateway')
            && getenv('BREF_LOOP_MAX') !== false;
    }
}
