<?php

declare(strict_types=1);

namespace Atymic\Xray\Instrumentation;

use Illuminate\Contracts\Foundation\Application;

/**
 * A source of spans.
 *
 * Implementations are listed in `config('xray.instrumentation')` and registered
 * once at boot. Registration happens on the application container, so adding
 * one is a config change rather than a code change.
 */
interface Instrumentation
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function register(Application $app, array $options = []): void;
}
