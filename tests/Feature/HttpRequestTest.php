<?php

declare(strict_types=1);

use Atymic\Xray\Http\Middleware\TraceRequest;
use Atymic\Xray\Trace\Sampler;
use Atymic\Xray\Trace\SpanKind;
use Atymic\Xray\Trace\SpanStatus;
use Atymic\Xray\Tracer;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::middleware('web')->group(function (): void {
        Route::get('ok', fn () => 'ok');
        Route::get('users/{id}', fn (string $id) => $id);
        Route::get('boom', fn () => abort(500));
        Route::get('missing', fn () => abort(404));
        Route::get('slow-down', fn () => abort(429));
        Route::get('health', fn () => 'up');
    });
});

it('traces a request', function (): void {
    $this->get('ok')->assertOk();

    $span = emitter()->first();

    expect($span)->not->toBeNull()
        ->and($span->kind)->toBe(SpanKind::Server)
        ->and($span->isRoot)->toBeTrue()
        ->and($span->isEnded())->toBeTrue()
        ->and($span->attributes())->toMatchArray([
            'http.request.method' => 'GET',
            'http.response.status_code' => 200,
            'url.path' => '/ok',
        ]);
});

it('names the span by route pattern, not the concrete url', function (): void {
    // Concrete ids would make every request a distinct node on the service map.
    $this->get('users/123')->assertOk();

    expect(emitter()->first()->name)->toBe('GET /users/{id}')
        ->and(emitter()->first()->getAttribute('http.route'))->toBe('users/{id}');
});

it('continues an inbound trace', function (): void {
    $this->withHeader(
        'X-Amzn-Trace-Id',
        'Root=1-5759e988-bd862e3fe1be46a994272793;Parent=53995c3f42cd8ad8;Sampled=1',
    )->get('ok')->assertOk();

    $span = emitter()->first();

    expect($span->traceId)->toBe('1-5759e988-bd862e3fe1be46a994272793')
        ->and($span->parentSpanId)->toBe('53995c3f42cd8ad8');
});

it('honours an upstream decision not to sample', function (): void {
    $this->withHeader(
        'X-Amzn-Trace-Id',
        'Root=1-5759e988-bd862e3fe1be46a994272793;Sampled=0',
    )->get('ok')->assertOk();

    expect(emitter()->spans())->toBeEmpty();
});

it('starts its own trace when none arrives', function (): void {
    $this->get('ok')->assertOk();

    expect(emitter()->first()->traceId)->toMatch('/^1-[0-9a-f]{8}-[0-9a-f]{24}$/');
});

it('maps http status onto x-ray failure kinds', function (string $uri, SpanStatus $expected): void {
    $this->get($uri);

    expect(emitter()->first()->status())->toBe($expected);
})->with([
    ['ok', SpanStatus::Ok],
    ['missing', SpanStatus::Error],
    ['boom', SpanStatus::Fault],
    ['slow-down', SpanStatus::Throttle],
]);

it('skips excluded paths entirely', function (): void {
    // Cheaper than sampling them out: no trace is even started. Rebuild the
    // middleware so it picks up the exclusion.
    app()->forgetInstance(TraceRequest::class);
    config()->set('xray.http.except', ['/health']);

    $this->get('health')->assertOk();

    expect(emitter()->spans())->toBeEmpty();
});

it('does not sample paths ruled out by config', function (): void {
    app()->forgetInstance(Sampler::class);
    config()->set('xray.sampling.rules', [['path' => '/ok', 'rate' => 0.0]]);

    // The tracer holds the sampler, so it has to be rebuilt too.
    app()->forgetInstance(Tracer::class);
    app()->forgetInstance(TraceRequest::class);

    $this->get('ok')->assertOk();

    expect(emitter()->spans())->toBeEmpty();
});

it('emits exactly one root span per request', function (): void {
    $this->get('ok')->assertOk();
    $this->get('ok')->assertOk();

    $roots = array_filter(emitter()->spans(), fn ($s) => $s->isRoot);

    expect($roots)->toHaveCount(2)
        ->and(emitter()->traceIds())->toHaveCount(2);
});
