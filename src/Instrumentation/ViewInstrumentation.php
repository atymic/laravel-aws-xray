<?php

declare(strict_types=1);

namespace Atymic\Xray\Instrumentation;

use Atymic\Xray\Tracer;
use Illuminate\Contracts\Foundation\Application;

/**
 * Records view rendering as span events.
 *
 * Laravel fires `composing:` when a view is about to render but has no matching
 * "finished" event, so a true duration is not available without decorating the
 * engine resolver. Events record what rendered without pretending to time it.
 */
final class ViewInstrumentation implements Instrumentation
{
    public function register(Application $app, array $options = []): void
    {
        $tracer = $app->make(Tracer::class);

        $app['events']->listen('composing:*', static function (string $event, array $data) use ($tracer): void {
            $view = $data[0] ?? null;

            if (! is_object($view) || ! method_exists($view, 'name')) {
                return;
            }

            $tracer->currentSpan()?->addEvent('view.render', ['view.name' => $view->name()]);
        });
    }
}
