<?php

declare(strict_types=1);

namespace Atymic\Xray\Emitter;

use Atymic\Xray\Serializer\XraySegmentSerializer;
use Psr\Log\LoggerInterface;

/**
 * Writes segment documents to the application log.
 *
 * For local development, where running a daemon or a collector is not worth the
 * trouble but seeing what would have been emitted is. Uses the X-Ray shape
 * because it is the more readable of the two.
 */
final readonly class LogEmitter implements Emitter
{
    public function __construct(
        private LoggerInterface $logger,
        private XraySegmentSerializer $serializer,
        private string $level = 'debug',
    ) {}

    public function emit(array $spans): void
    {
        foreach ($spans as $span) {
            $this->logger->log($this->level, 'xray span', $this->serializer->serialize($span));
        }
    }

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
