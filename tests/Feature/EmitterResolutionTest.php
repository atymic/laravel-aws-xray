<?php

declare(strict_types=1);

use Atymic\Xray\Emitter\CollectorEmitter;
use Atymic\Xray\Emitter\Emitter;
use Atymic\Xray\Emitter\LogEmitter;
use Atymic\Xray\Emitter\NullEmitter;
use Atymic\Xray\Emitter\OtlpEmitter;
use Atymic\Xray\Emitter\UdpDaemonEmitter;
use Atymic\Xray\XrayServiceProvider;

afterEach(function (): void {
    foreach ([
        'AWS_XRAY_DAEMON_ADDRESS',
        'OTEL_EXPORTER_OTLP_ENDPOINT',
        'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT',
        'AWS_ACCESS_KEY_ID',
        'AWS_SECRET_ACCESS_KEY',
    ] as $key) {
        putenv($key);
    }
});

function emitterFor(string $driver): Emitter
{
    config()->set('xray.emitter', $driver);
    app()->forgetInstance(Emitter::class);

    return app(Emitter::class);
}

it('resolves each configured driver', function (string $driver, string $expected): void {
    expect(emitterFor($driver))->toBeInstanceOf($expected);
})->with([
    ['xray', UdpDaemonEmitter::class],
    ['collector', CollectorEmitter::class],
    ['otlp', OtlpEmitter::class],
    ['log', LogEmitter::class],
    ['null', NullEmitter::class],
]);

it('falls back to null for an unknown driver rather than failing boot', function (): void {
    expect(emitterFor('nonsense'))->toBeInstanceOf(NullEmitter::class);
});

/**
 * Auto-resolution is invoked directly: `auto` deliberately short-circuits to
 * NullEmitter under a test suite, so an application's own tests never depend on
 * a daemon or emit anything.
 */
function autoResolved(): Emitter
{
    $provider = new XrayServiceProvider(app());
    $method = new ReflectionMethod($provider, 'autoEmitter');

    return $method->invoke($provider);
}

it('short circuits to null under a test suite', function (): void {
    putenv('AWS_XRAY_DAEMON_ADDRESS=127.0.0.1:2000');

    expect(emitterFor('auto'))->toBeInstanceOf(NullEmitter::class);
});

it('prefers the collector when one is configured', function (): void {
    // It keeps work off the request path *and* leaves sampling to us, so it
    // wins over the daemon when both are present.
    putenv('OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:4318');
    putenv('AWS_XRAY_DAEMON_ADDRESS=127.0.0.1:2000');

    expect(autoResolved())->toBeInstanceOf(CollectorEmitter::class);
});

it('uses the daemon when only active tracing is available', function (): void {
    putenv('AWS_XRAY_DAEMON_ADDRESS=127.0.0.1:2000');

    expect(autoResolved())->toBeInstanceOf(UdpDaemonEmitter::class);
})->skip(fn () => ! function_exists('socket_create'), 'ext-sockets unavailable');

it('never auto-resolves to the blocking otlp transport', function (): void {
    // OtlpEmitter::isAvailable() is curl + credentials, and Lambda injects
    // credentials everywhere — so including it in the chain made every function
    // without a collector or daemon POST each span synchronously before the
    // handler could return. `auto` must never pick the transport that blocks
    // the response; it has to be named explicitly.
    putenv('AWS_XRAY_DAEMON_ADDRESS');
    putenv('OTEL_EXPORTER_OTLP_ENDPOINT');
    putenv('AWS_ACCESS_KEY_ID=AKIAIOSFODNN7EXAMPLE');
    putenv('AWS_SECRET_ACCESS_KEY=wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY');

    expect(autoResolved())->not->toBeInstanceOf(OtlpEmitter::class);
});

it('reports the tracing mode each emitter needs', function (): void {
    // Only the UDP path depends on `tracing: Active`, because that is what
    // makes Lambda run a daemon.
    expect(emitterFor('xray')->requiresTracingMode())->toBe('Active')
        ->and(emitterFor('collector')->requiresTracingMode())->toBeNull()
        ->and(emitterFor('otlp')->requiresTracingMode())->toBeNull();
});

it('knows the collector is unavailable without an endpoint', function (): void {
    expect(emitterFor('collector')->isAvailable())->toBeFalse();

    putenv('OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:4318');

    expect(emitterFor('collector')->isAvailable())->toBeTrue();
});

it('appends the traces path to a bare collector endpoint', function (): void {
    putenv('OTEL_EXPORTER_OTLP_ENDPOINT=http://collector:4318');

    $endpoint = new ReflectionProperty(emitterFor('collector'), 'endpoint');

    expect($endpoint->getValue(emitterFor('collector')))->toBe('http://collector:4318/v1/traces');
});

it('leaves an endpoint that already names the path alone', function (): void {
    putenv('OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=http://collector:4318/v1/traces');

    $endpoint = new ReflectionProperty(emitterFor('collector'), 'endpoint');

    expect($endpoint->getValue(emitterFor('collector')))->toBe('http://collector:4318/v1/traces');
});

it('defaults the otlp endpoint to the regional x-ray host', function (): void {
    config()->set('xray.otlp.region', 'ap-southeast-2');
    config()->set('xray.otlp.endpoint', null);

    $emitter = emitterFor('otlp');
    $url = new ReflectionMethod($emitter, 'url');

    expect($url->invoke($emitter))->toBe('https://xray.ap-southeast-2.amazonaws.com/v1/traces');
});
