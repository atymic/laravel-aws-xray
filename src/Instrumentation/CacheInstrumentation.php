<?php

declare(strict_types=1);

namespace Atymic\Xray\Instrumentation;

use Atymic\Xray\Tracer;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Contracts\Foundation\Application;

/**
 * Records cache activity as span *events* rather than spans.
 *
 * Cache operations are typically sub-millisecond and very frequent; a span each
 * would dominate a trace's span count and, on the per-byte billing of
 * Transaction Search, its cost — while adding little that an event does not.
 */
final class CacheInstrumentation implements Instrumentation
{
    public function register(Application $app, array $options = []): void
    {
        $events = $app['events'];
        $tracer = $app->make(Tracer::class);

        $record = static function (string $operation, string $key, ?string $store) use ($tracer): void {
            $tracer->currentSpan()?->addEvent('cache.'.$operation, [
                'cache.key' => $key,
                'cache.store' => $store,
            ]);
        };

        $events->listen(CacheHit::class, static fn (CacheHit $e) => $record('hit', $e->key, $e->storeName));
        $events->listen(CacheMissed::class, static fn (CacheMissed $e) => $record('miss', $e->key, $e->storeName));
        $events->listen(KeyWritten::class, static fn (KeyWritten $e) => $record('write', $e->key, $e->storeName));
        $events->listen(KeyForgotten::class, static fn (KeyForgotten $e) => $record('forget', $e->key, $e->storeName));
    }
}
