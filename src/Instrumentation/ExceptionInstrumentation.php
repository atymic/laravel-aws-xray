<?php

declare(strict_types=1);

namespace Atymic\Xray\Instrumentation;

use Atymic\Xray\Tracer;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Exceptions\Handler;
use Throwable;

/**
 * Attaches reported exceptions to the active span.
 *
 * Without this a 500 shows as a failed span with no explanation. The exception
 * is recorded on whichever span is innermost at the time, so a failure inside a
 * database call is attributed to that call rather than to the request as a
 * whole.
 */
final class ExceptionInstrumentation implements Instrumentation
{
    public function register(Application $app, array $options = []): void
    {
        $handler = $app->make(ExceptionHandler::class);

        if (! $handler instanceof Handler) {
            return;
        }

        $tracer = $app->make(Tracer::class);
        $stackLimit = (int) ($options['stack_limit'] ?? 10);

        $handler->reportable(static function (Throwable $e) use ($tracer, $stackLimit): void {
            $tracer->currentSpan()?->recordException($e, $stackLimit);
        });
    }
}
