<?php

declare(strict_types=1);

namespace Atymic\Xray\Instrumentation;

use Atymic\Xray\Trace\SpanKind;
use Atymic\Xray\Tracer;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Redis\Events\CommandExecuted;

/**
 * Spans for Redis commands.
 *
 * Like database queries, `CommandExecuted` fires after the fact and carries the
 * duration, so spans are backdated rather than wrapping the call.
 */
final class RedisInstrumentation implements Instrumentation
{
    public function register(Application $app, array $options = []): void
    {
        $tracer = $app->make(Tracer::class);
        $maxLength = (int) ($options['max_command_length'] ?? 200);

        // Redis does not emit events unless asked to.
        $app->make('redis')->enableEvents();

        $app['events']->listen(CommandExecuted::class, function (CommandExecuted $event) use ($tracer, $maxLength): void {
            if (! $tracer->isRecording()) {
                return;
            }

            $duration = $event->time / 1000;
            $end = microtime(true);

            $span = $tracer->startSpan(
                name: 'redis '.strtoupper($event->command),
                kind: SpanKind::Client,
                attributes: [
                    'db.system.name' => 'redis',
                    'db.operation.name' => strtoupper($event->command),
                    'db.query.text' => $this->summarise($event->command, $event->parameters, $maxLength),
                    'db.connection.name' => $event->connectionName,
                    'db.duration_ms' => $event->time,
                ],
                startTime: $end - $duration,
            );

            $tracer->endSpan($span, $end);
        });
    }

    /**
     * Command plus its key, without values — values are unbounded in size and
     * frequently sensitive.
     *
     * @param  array<int, mixed>  $parameters
     */
    private function summarise(string $command, array $parameters, int $max): string
    {
        $key = $parameters[0] ?? null;

        $summary = is_scalar($key)
            ? strtoupper($command).' '.$key
            : strtoupper($command);

        return mb_strlen($summary) <= $max ? $summary : mb_substr($summary, 0, $max).'…';
    }
}
