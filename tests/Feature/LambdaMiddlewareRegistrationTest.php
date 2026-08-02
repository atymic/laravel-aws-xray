<?php

declare(strict_types=1);

use Atymic\Xray\Http\Middleware\TraceRequest;
use Atymic\Xray\XrayServiceProvider;
use Illuminate\Contracts\Http\Kernel;

/**
 * Bref serves HTTP under the CLI SAPI, so `runningInConsole()` is true for web
 * requests on Lambda. Registering middleware on that basis disables the package
 * in the one environment it targets, silently — no span, no warning.
 *
 * These drive the decision directly rather than through a request, because
 * Testbench always reports `runningUnitTests()` and so can never reproduce the
 * production condition through the HTTP stack.
 */
function decidesItIsAConsoleCommand(): bool
{
    $provider = new XrayServiceProvider(app());
    $method = new ReflectionMethod($provider, 'isConsoleCommand');

    return $method->invoke($provider);
}

afterEach(function (): void {
    foreach (['AWS_LAMBDA_FUNCTION_NAME', 'BREF_RUNTIME'] as $key) {
        putenv($key);
    }
});

it('does not mistake a bref http request for a console command', function (string $runtime): void {
    putenv('AWS_LAMBDA_FUNCTION_NAME=addcal-web');
    putenv('BREF_RUNTIME='.$runtime);

    expect(decidesItIsAConsoleCommand())->toBeFalse();
})->with(['function', 'fpm']);

it('recognises the bref console runtime as a command', function (): void {
    putenv('AWS_LAMBDA_FUNCTION_NAME=addcal-artisan');
    putenv('BREF_RUNTIME=console');

    expect(decidesItIsAConsoleCommand())->toBeTrue();
});

it('treats a lambda with no declared runtime as serving http', function (): void {
    // The handler may be set in the image CMD rather than the environment. HTTP
    // is the safer default: tracing a command that was not asked for is a far
    // smaller problem than silently tracing nothing.
    putenv('AWS_LAMBDA_FUNCTION_NAME=addcal-web');

    expect(decidesItIsAConsoleCommand())->toBeFalse();
});

it('registers the request middleware under a bref http runtime', function (): void {
    putenv('AWS_LAMBDA_FUNCTION_NAME=addcal-web');
    putenv('BREF_RUNTIME=function');

    // Re-run registration against a kernel we can inspect.
    (new XrayServiceProvider(app()))->packageBooted();

    $kernel = app(Kernel::class);
    $middleware = (new ReflectionProperty($kernel, 'middleware'))->getValue($kernel);

    expect($middleware)->toContain(TraceRequest::class);
});
