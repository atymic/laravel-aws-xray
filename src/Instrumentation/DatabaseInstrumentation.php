<?php

declare(strict_types=1);

namespace Atymic\Xray\Instrumentation;

use Atymic\Xray\Trace\SpanKind;
use Atymic\Xray\Tracer;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;

/**
 * Spans for database queries.
 *
 * `QueryExecuted` fires *after* the query completes and carries its duration,
 * so spans are backdated rather than opened and closed around the call. That
 * keeps the instrumentation out of the query path entirely.
 */
final class DatabaseInstrumentation implements Instrumentation
{
    public function register(Application $app, array $options = []): void
    {
        $events = $app['events'];
        $tracer = $app->make(Tracer::class);

        $maxLength = (int) ($options['max_query_length'] ?? 500);
        $includeBindings = (bool) ($options['include_bindings'] ?? false);

        $events->listen(QueryExecuted::class, function (QueryExecuted $event) use ($tracer, $maxLength, $includeBindings): void {
            if (! $tracer->isRecording()) {
                return;
            }

            $duration = $event->time / 1000;
            $end = microtime(true);

            $span = $tracer->startSpan(
                name: $this->name($event),
                kind: SpanKind::Client,
                attributes: $this->attributes($event, $maxLength, $includeBindings),
                startTime: $end - $duration,
            );

            $tracer->endSpan($span, $end);
        });

        if ($options['transactions'] ?? true) {
            $this->registerTransactions($events, $tracer);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(QueryExecuted $event, int $maxLength, bool $includeBindings): array
    {
        $attributes = [
            'db.system.name' => $event->connection->getDriverName(),
            'db.namespace' => $event->connection->getDatabaseName(),
            'db.query.text' => $this->truncate($event->sql, $maxLength),
            'db.operation.name' => $this->operation($event->sql),
            'db.connection.name' => $event->connectionName,
            'db.duration_ms' => $event->time,
        ];

        // Off by default: bindings routinely contain personal data, and
        // Transaction Search bills per byte.
        if ($includeBindings) {
            $attributes['db.query.parameters'] = $this->truncate(
                json_encode($event->bindings, JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '',
                $maxLength,
            );
        }

        $host = $event->connection->getConfig('host');

        if (is_string($host)) {
            $attributes['server.address'] = $host;
        }

        $port = $event->connection->getConfig('port');

        if (is_int($port) || is_string($port)) {
            $attributes['server.port'] = (int) $port;
        }

        return $attributes;
    }

    /**
     * X-Ray renders a database node per `name`, so keep it low-cardinality:
     * the operation and connection, never the full statement.
     */
    private function name(QueryExecuted $event): string
    {
        return trim(sprintf(
            '%s %s',
            $this->operation($event->sql),
            $event->connection->getDatabaseName() ?: $event->connectionName,
        ));
    }

    private function operation(string $sql): string
    {
        $first = strtoupper(strtok(ltrim($sql), " \n\t\r") ?: '');

        return in_array($first, ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER', 'TRUNCATE'], true)
            ? $first
            : 'QUERY';
    }

    private function truncate(string $value, int $max): string
    {
        return mb_strlen($value) <= $max ? $value : mb_substr($value, 0, $max).'…';
    }

    private function registerTransactions(mixed $events, Tracer $tracer): void
    {
        $spans = new \SplObjectStorage;

        $events->listen(TransactionBeginning::class, function ($event) use ($tracer, $spans): void {
            if (! $tracer->isRecording()) {
                return;
            }

            $span = $tracer->startSpan('transaction', SpanKind::Client, [
                'db.system.name' => $event->connection->getDriverName(),
                'db.namespace' => $event->connection->getDatabaseName(),
            ]);

            if ($span !== null) {
                $spans[$event->connection] = $span;
            }
        });

        $close = function ($event) use ($tracer, $spans): void {
            if (! isset($spans[$event->connection])) {
                return;
            }

            $span = $spans[$event->connection];
            unset($spans[$event->connection]);

            $tracer->endSpan($span);
        };

        $events->listen(TransactionCommitted::class, $close);
        $events->listen(TransactionRolledBack::class, $close);
    }
}
