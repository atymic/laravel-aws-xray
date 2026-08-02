<?php

declare(strict_types=1);

namespace Atymic\Xray\Instrumentation;

use Atymic\Xray\Lambda\ColdStart;
use Atymic\Xray\Trace\Span;
use Atymic\Xray\Trace\SpanKind;
use Atymic\Xray\Trace\SpanStatus;
use Atymic\Xray\Tracer;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Str;

/**
 * Traces artisan commands.
 *
 * Opt-in by name: scheduled commands run constantly, and tracing all of them
 * would swamp the traces that matter. `commands` defaults to empty, so nothing
 * is traced until listed (wildcards allowed).
 */
final class ConsoleInstrumentation implements Instrumentation
{
    public function register(Application $app, array $options = []): void
    {
        /** @var list<string> $patterns */
        $patterns = $options['commands'] ?? [];

        if ($patterns === []) {
            return;
        }

        $events = $app['events'];
        $tracer = $app->make(Tracer::class);

        $span = null;

        $events->listen(CommandStarting::class, function (CommandStarting $event) use ($tracer, $patterns, &$span): void {
            if (! $this->matches($event->command, $patterns)) {
                return;
            }

            $tracer->startTrace();

            $span = $tracer->startSpan(
                name: 'artisan '.$event->command,
                kind: SpanKind::Server,
                attributes: [
                    'console.command' => $event->command,
                    ...ColdStart::claim(),
                ],
            );
        });

        $events->listen(CommandFinished::class, static function (CommandFinished $event) use ($tracer, &$span): void {
            if (! $span instanceof Span) {
                return;
            }

            $span->setAttribute('console.exit_code', $event->exitCode);

            if ($event->exitCode !== 0) {
                $span->setStatus(SpanStatus::Fault);
            }

            $tracer->endSpan($span);
            $span = null;
            $tracer->endTrace();
        });
    }

    /**
     * @param  list<string>  $patterns
     */
    private function matches(string $command, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (Str::is($pattern, $command)) {
                return true;
            }
        }

        return false;
    }
}
