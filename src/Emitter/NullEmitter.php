<?php

declare(strict_types=1);

namespace Atymic\Xray\Emitter;

/**
 * Discards everything. The default under `testing`, and what a disabled
 * package falls back to, so instrumentation can stay wired up without any
 * transport being configured.
 */
final class NullEmitter implements Emitter
{
    public function emit(array $spans): void {}

    public function flush(): void {}

    public function isAvailable(): bool
    {
        return true;
    }

    public function requiresTracingMode(): ?string
    {
        return null;
    }
}
