<?php

declare(strict_types=1);

use Atymic\Xray\Instrumentation\DatabaseInstrumentation;
use Atymic\Xray\Trace\SpanKind;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);

    Schema::create('users', function ($table): void {
        $table->id();
        $table->string('name');
    });

    registerInstrumentation(DatabaseInstrumentation::class);
});

it('does not record queries outside a trace', function (): void {
    // Instrumentation must be inert when nothing is being traced — otherwise a
    // queue worker or scheduled command silently accumulates spans.
    DB::table('users')->get();

    expect(emitter()->spans())->toBeEmpty();
});

it('records a query span', function (): void {
    withTrace(fn () => DB::table('users')->get());

    $span = recordedSpan('SELECT :memory:');

    expect($span)->not->toBeNull()
        ->and($span->kind)->toBe(SpanKind::Client)
        ->and($span->isEnded())->toBeTrue()
        ->and($span->attributes())->toMatchArray([
            'db.system.name' => 'sqlite',
            'db.query.text' => 'select * from "users"',
            'db.operation.name' => 'SELECT',
        ]);
});

it('keeps the span name low cardinality', function (): void {
    // The statement itself would put every distinct query on the service map.
    withTrace(function (): void {
        DB::table('users')->where('id', 1)->get();
        DB::table('users')->where('id', 2)->get();
    });

    $names = array_map(fn ($s) => $s->name, emitter()->spans());

    expect(array_unique(array_filter($names, fn ($n) => str_starts_with($n, 'SELECT'))))
        ->toHaveCount(1);
});

it('preserves placeholders rather than interpolating values', function (): void {
    withTrace(fn () => DB::table('users')->where('name', 'John')->get());

    expect(recordedSpan('SELECT :memory:')->getAttribute('db.query.text'))
        ->toBe('select * from "users" where "name" = ?');
});

it('omits bindings by default', function (): void {
    // Bindings routinely carry personal data, and spans are billed by the byte.
    withTrace(fn () => DB::table('users')->where('name', 'Jane')->get());

    expect(recordedSpan('SELECT :memory:')->getAttribute('db.query.parameters'))->toBeNull();
});

it('includes bindings when asked', function (): void {
    registerInstrumentation(DatabaseInstrumentation::class, ['include_bindings' => true]);

    withTrace(fn () => DB::table('users')->where('name', 'Jane')->get());

    $spans = array_values(array_filter(
        emitter()->spans(),
        fn ($s) => $s->getAttribute('db.query.parameters') !== null,
    ));

    expect($spans)->not->toBeEmpty()
        ->and($spans[0]->getAttribute('db.query.parameters'))->toContain('Jane');
});

it('truncates long statements', function (): void {
    registerInstrumentation(DatabaseInstrumentation::class, ['max_query_length' => 20]);

    withTrace(fn () => DB::table('users')->where('name', str_repeat('x', 200))->get());

    $long = array_values(array_filter(
        emitter()->spans(),
        fn ($s) => is_string($s->getAttribute('db.query.text'))
            && str_ends_with((string) $s->getAttribute('db.query.text'), '…'),
    ));

    expect($long)->not->toBeEmpty()
        ->and(mb_strlen((string) $long[0]->getAttribute('db.query.text')))->toBe(21);
});

it('backdates the span to when the query actually ran', function (): void {
    // QueryExecuted fires after the fact, so the span is reconstructed from the
    // reported duration rather than wrapping the call.
    withTrace(fn () => DB::table('users')->get());

    $span = recordedSpan('SELECT :memory:');

    expect($span->duration())->toBeGreaterThanOrEqual(0.0)
        ->and($span->startTime)->toBeLessThanOrEqual($span->endTime());
});

it('nests query spans under the active span', function (): void {
    $root = null;

    withTrace(function ($span) use (&$root): void {
        $root = $span;
        DB::table('users')->get();
    });

    expect(recordedSpan('SELECT :memory:')->parentSpanId)->toBe($root->spanId);
});
