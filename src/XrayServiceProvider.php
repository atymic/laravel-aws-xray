<?php

declare(strict_types=1);

namespace Atymic\Xray;

use Atymic\Xray\Emitter\CollectorEmitter;
use Atymic\Xray\Emitter\Emitter;
use Atymic\Xray\Emitter\LogEmitter;
use Atymic\Xray\Emitter\MemoryEmitter;
use Atymic\Xray\Emitter\NullEmitter;
use Atymic\Xray\Emitter\OtlpEmitter;
use Atymic\Xray\Emitter\UdpDaemonEmitter;
use Atymic\Xray\Http\Middleware\TraceRequest;
use Atymic\Xray\Http\SigV4Signer;
use Atymic\Xray\Instrumentation\Instrumentation;
use Atymic\Xray\Lambda\Environment;
use Atymic\Xray\Octane\RequestScope;
use Atymic\Xray\Serializer\OtlpSerializer;
use Atymic\Xray\Serializer\XraySegmentSerializer;
use Atymic\Xray\Trace\IdGenerator;
use Atymic\Xray\Trace\Sampler;
use Illuminate\Contracts\Http\Kernel;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class XrayServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-aws-xray')
            ->hasConfigFile('xray');
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(IdGenerator::class, static fn () => new IdGenerator);

        $this->app->singleton(Sampler::class, function () {
            /** @var array{rate: float, rules: list<array{path: string, rate: float}>} $config */
            $config = $this->app['config']->get('xray.sampling', []);

            return new Sampler(
                rate: (float) ($config['rate'] ?? 1.0),
                rules: $config['rules'] ?? [],
            );
        });

        $this->app->singleton(XraySegmentSerializer::class, function () {
            $config = $this->app['config'];

            return new XraySegmentSerializer(
                serviceName: (string) $config->get('xray.service.name', 'laravel'),
                annotationKeys: $config->get('xray.annotations', []),
                serviceVersion: $config->get('xray.service.version'),
                origin: Environment::isLambda() ? 'AWS::Lambda::Function' : null,
            );
        });

        $this->app->singleton(OtlpSerializer::class, function () {
            $config = $this->app['config'];

            return new OtlpSerializer(
                serviceName: (string) $config->get('xray.service.name', 'laravel'),
                ids: $this->app->make(IdGenerator::class),
                resourceAttributes: Environment::resourceAttributes(),
            );
        });

        // Shared so a test can assert on the same instance the tracer writes to.
        $this->app->singleton(MemoryEmitter::class, static fn () => new MemoryEmitter);

        $this->app->singleton(Emitter::class, fn () => $this->resolveEmitter());

        // The Tracer is a singleton, but the request-scoped state lives in the
        // TraceContext it holds, which RequestScope replaces per request. That
        // separation is what keeps the package correct under Octane.
        $this->app->singleton(Tracer::class, function () {
            return new Tracer(
                emitter: $this->app->make(Emitter::class),
                sampler: $this->app->make(Sampler::class),
                ids: $this->app->make(IdGenerator::class),
                // Under Active tracing Lambda creates the function segment and
                // it is read-only, so everything we emit must be a subsegment.
                emitAsSubsegments: Environment::isLambda() && Environment::hasActiveTracing(),
            );
        });
    }

    public function packageBooted(): void
    {
        if (! $this->app['config']->get('xray.enabled', true)) {
            // Rebind rather than resolving the Tracer here to disable it:
            // resolving would build the configured emitter, and under `auto`
            // that means running the SDK credential chain — an IMDS round trip
            // on every cold start, for a package that is switched off. No
            // middleware or instrumentation is registered below, so nothing
            // starts a span; this only covers direct `Xray::trace()` calls.
            $this->app->singleton(Emitter::class, static fn () => new NullEmitter);

            $this->app->extend(Tracer::class, static function (Tracer $tracer): Tracer {
                $tracer->disable();

                return $tracer;
            });

            return;
        }

        $this->registerMiddleware();
        $this->registerInstrumentation();
        $this->registerOctaneHooks();
    }

    private function registerMiddleware(): void
    {
        // `runningInConsole()` is TRUE on Lambda: Bref serves HTTP under the CLI
        // SAPI, so php_sapi_name() is `cli` for web requests too. Skipping
        // registration on that basis silently disables the package in exactly
        // the environment it targets — no root span, nothing emitted, and no
        // warning to explain it. Only skip when this is genuinely a console
        // command, which Lambda is not.
        if ($this->isConsoleCommand()) {
            return;
        }

        $config = $this->app['config'];

        $this->app->singleton(TraceRequest::class, fn () => new TraceRequest(
            tracer: $this->app->make(Tracer::class),
            except: $config->get('xray.http.except', []),
            captureHeaders: $config->get('xray.http.capture_headers', []),
        ));

        // Prepended so the root span covers as much of the request as possible,
        // including other middleware.
        $this->app->make(Kernel::class)->prependMiddleware(TraceRequest::class);
    }

    /**
     * Whether this process is really running an artisan command.
     *
     * Deliberately not `runningInConsole()`, which asks the SAPI and therefore
     * answers "yes" to every Bref HTTP request. On Lambda the runtime says what
     * it is: `BREF_RUNTIME=console` and the console handler both indicate an
     * actual command, while `function`/`fpm` serve HTTP. Off Lambda, the SAPI
     * answer is the right one.
     */
    private function isConsoleCommand(): bool
    {
        // Checked before the test-suite guard so the Lambda behaviour is
        // reachable from a test at all — otherwise the one condition that
        // matters here could never be reproduced.
        if (Environment::isLambda()) {
            return (getenv('BREF_RUNTIME') ?: '') === 'console';
        }

        if ($this->app->runningUnitTests()) {
            return false;
        }

        return $this->app->runningInConsole();
    }

    private function registerInstrumentation(): void
    {
        /** @var array<class-string<Instrumentation>, array<string, mixed>> $configured */
        $configured = $this->app['config']->get('xray.instrumentation', []);

        foreach ($configured as $class => $options) {
            if (($options['enabled'] ?? true) !== true) {
                continue;
            }

            if (! class_exists($class) || ! is_subclass_of($class, Instrumentation::class)) {
                continue;
            }

            $this->app->make($class)->register($this->app, $options);
        }
    }

    private function registerOctaneHooks(): void
    {
        if (! RequestScope::isOctane()) {
            return;
        }

        (new RequestScope($this->app->make(Tracer::class)))->register($this->app['events']);
    }

    /**
     * Resolve the configured emitter, falling back when the chosen one cannot
     * actually deliver here.
     */
    private function resolveEmitter(): Emitter
    {
        $driver = (string) $this->app['config']->get('xray.emitter', 'auto');

        if ($driver !== 'auto') {
            return $this->makeEmitter($driver) ?? new NullEmitter;
        }

        // Never let `auto` pick a real transport in a test suite: an
        // application's tests should not depend on a daemon or emit anything.
        if ($this->app->runningUnitTests()) {
            return new NullEmitter;
        }

        return $this->autoEmitter();
    }

    /**
     * Prefer the transport that keeps work off the request path, then the one
     * that is already running.
     *
     * `otlp` is deliberately not in this chain. Its availability check is
     * `curl_init() && $signer->hasCredentials()`, and Lambda injects
     * credentials into every execution environment — so on any function without
     * a collector or a daemon it resolves true unconditionally, turning every
     * span into a blocking signed HTTPS POST on the request path. That is the
     * one transport that delays the response, so it must be asked for by name
     * rather than arrived at by elimination.
     */
    private function autoEmitter(): Emitter
    {
        foreach (['collector', 'xray'] as $driver) {
            $emitter = $this->makeEmitter($driver);

            if ($emitter?->isAvailable() === true) {
                return $emitter;
            }
        }

        return $this->app->environment('local')
            ? $this->makeEmitter('log') ?? new NullEmitter
            : new NullEmitter;
    }

    private function makeEmitter(string $driver): ?Emitter
    {
        $config = $this->app['config'];
        $logger = $this->app->make('log');

        return match ($driver) {
            'xray' => UdpDaemonEmitter::fromEnvironment(
                $this->app->make(XraySegmentSerializer::class),
                $logger,
                $config->get('xray.daemon.address'),
            ),
            'collector' => CollectorEmitter::fromEnvironment(
                $this->app->make(OtlpSerializer::class),
                $logger,
                $config->get('xray.collector.endpoint'),
                (float) $config->get('xray.collector.timeout', 1.0),
            ),
            'otlp' => new OtlpEmitter(
                serializer: $this->app->make(OtlpSerializer::class),
                signer: new SigV4Signer((string) $config->get('xray.otlp.region', 'us-east-1')),
                region: (string) $config->get('xray.otlp.region', 'us-east-1'),
                endpoint: $config->get('xray.otlp.endpoint'),
                timeout: (float) $config->get('xray.otlp.timeout', 3.0),
                logger: $logger,
            ),
            'log' => new LogEmitter($logger, $this->app->make(XraySegmentSerializer::class)),
            'memory' => $this->app->make(MemoryEmitter::class),
            'null' => new NullEmitter,
            default => null,
        };
    }
}
