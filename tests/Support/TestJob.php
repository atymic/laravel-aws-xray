<?php

declare(strict_types=1);

namespace Atymic\Xray\Tests\Support;

use Atymic\Xray\Tracer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class TestJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Trace id observed from inside the job, for asserting propagation. */
    public static ?string $observedTraceId = null;

    public static bool $shouldFail = false;

    public function handle(Tracer $tracer): void
    {
        self::$observedTraceId = $tracer->context()?->traceId;

        if (self::$shouldFail) {
            throw new \RuntimeException('job failed');
        }
    }

    public static function reset(): void
    {
        self::$observedTraceId = null;
        self::$shouldFail = false;
    }
}
