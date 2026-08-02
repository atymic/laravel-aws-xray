<?php

declare(strict_types=1);

namespace Atymic\Xray\Lambda;

use Atymic\Xray\Trace\TraceHeader;

/**
 * Facts about the Lambda execution environment.
 *
 * Every read goes through `getenv()` rather than `$_ENV`/`$_SERVER`, and
 * nothing is cached. Lambda re-sets `_X_AMZN_TRACE_ID` on every invocation
 * while an Octane worker persists across many of them, so a value captured at
 * boot would pin every request in the container to the first invocation's
 * trace. This is the most common way a Laravel X-Ray integration silently
 * breaks under Octane.
 */
final class Environment
{
    /** Whether the process is running inside Lambda at all. */
    public static function isLambda(): bool
    {
        return getenv('AWS_LAMBDA_FUNCTION_NAME') !== false;
    }

    public static function functionName(): ?string
    {
        return self::env('AWS_LAMBDA_FUNCTION_NAME');
    }

    public static function functionVersion(): ?string
    {
        return self::env('AWS_LAMBDA_FUNCTION_VERSION');
    }

    public static function region(): ?string
    {
        return self::env('AWS_REGION') ?? self::env('AWS_DEFAULT_REGION');
    }

    public static function memorySize(): ?int
    {
        $value = self::env('AWS_LAMBDA_FUNCTION_MEMORY_SIZE');

        return $value === null ? null : (int) $value;
    }

    public static function logStream(): ?string
    {
        return self::env('AWS_LAMBDA_LOG_STREAM_NAME');
    }

    /**
     * The current invocation's trace header.
     *
     * Read fresh every call — see the class docblock.
     */
    public static function traceHeader(): TraceHeader
    {
        return TraceHeader::parse(self::env('_X_AMZN_TRACE_ID'));
    }

    /**
     * Whether the function has `tracing: Active`, inferred from the daemon
     * address Lambda injects in that mode.
     */
    public static function hasActiveTracing(): bool
    {
        return self::env('AWS_XRAY_DAEMON_ADDRESS') !== null
            || self::env('_AWS_XRAY_DAEMON_ADDRESS') !== null;
    }

    /**
     * `on-demand` for a normal cold start, `provisioned-concurrency` when the
     * environment was pre-warmed. Absent outside Lambda.
     */
    public static function initializationType(): ?string
    {
        return self::env('AWS_LAMBDA_INITIALIZATION_TYPE');
    }

    /**
     * Resource attributes describing this environment, for the OTLP resource
     * block and X-Ray's `aws` segment field.
     *
     * @return array<string, scalar>
     */
    public static function resourceAttributes(): array
    {
        return array_filter([
            'cloud.provider' => self::isLambda() ? 'aws' : null,
            'cloud.platform' => self::isLambda() ? 'aws_lambda' : null,
            'cloud.region' => self::region(),
            'faas.name' => self::functionName(),
            'faas.version' => self::functionVersion(),
            'faas.instance' => self::logStream(),
            'faas.max_memory' => self::memorySize(),
        ], static fn ($v) => $v !== null);
    }

    private static function env(string $key): ?string
    {
        $value = getenv($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
