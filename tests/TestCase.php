<?php

declare(strict_types=1);

namespace Atymic\Xray\Tests;

use Atymic\Xray\Emitter\MemoryEmitter;
use Atymic\Xray\Lambda\ColdStart;
use Atymic\Xray\XrayServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [XrayServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // The `web` middleware group encrypts cookies, which needs a key.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $app['config']->set('xray.enabled', true);
        $app['config']->set('xray.emitter', 'memory');
        $app['config']->set('xray.sampling.rate', 1.0);
        $app['config']->set('xray.sampling.rules', []);
        $app['config']->set('xray.service.name', 'test-service');
        $app['config']->set('xray.http.except', []);

        // Registered explicitly per test, so each one states what it depends on.
        $app['config']->set('xray.instrumentation', []);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
    }

    protected function setUp(): void
    {
        parent::setUp();

        ColdStart::reset();
        $this->emitter()->reset();
    }

    protected function emitter(): MemoryEmitter
    {
        return $this->app->make(MemoryEmitter::class);
    }
}
