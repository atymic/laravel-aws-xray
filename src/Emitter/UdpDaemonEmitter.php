<?php

declare(strict_types=1);

namespace Atymic\Xray\Emitter;

use Atymic\Xray\Serializer\XraySegmentSerializer;
use Atymic\Xray\Trace\Span;
use JsonException;
use Psr\Log\LoggerInterface;
use Socket;
use Throwable;

/**
 * Sends X-Ray segment documents to the daemon over UDP.
 *
 * The cheapest transport available on Lambda: `socket_sendto` returns once the
 * datagram is in the kernel buffer, so tracing costs microseconds and cannot
 * add latency to a response. There is no acknowledgement and no retry — a
 * dropped packet is a lost span, which is the trade being made.
 *
 * Requires `tracing: Active`, since that is what causes Lambda to run a daemon
 * and populate `AWS_XRAY_DAEMON_ADDRESS`. Sampling is then Lambda's decision
 * and is not configurable.
 *
 * @see https://docs.aws.amazon.com/xray/latest/devguide/xray-api-sendingdata.html
 */
final class UdpDaemonEmitter implements Emitter
{
    /** Documented daemon framing: a JSON header, newline, then the document. */
    private const HEADER = '{"format":"json","version":1}';

    /** Datagrams above this are fragmented rather than silently truncated. */
    private const MAX_SEGMENT_SIZE = 64_000;

    /** Null until first use; false once creation has failed and we stop retrying. */
    private Socket|false|null $socket = null;

    public function __construct(
        private readonly XraySegmentSerializer $serializer,
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 2000,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * @param  string|null  $configured  `xray.daemon.address`, when set to
     *                                   something other than the default. Lambda
     *                                   injects the real address, so the
     *                                   environment wins unless an operator has
     *                                   deliberately pointed this elsewhere.
     */
    public static function fromEnvironment(
        XraySegmentSerializer $serializer,
        ?LoggerInterface $logger = null,
        ?string $configured = null,
    ): self {
        // Re-read rather than trusting a cached value: the address is per
        // execution environment, and a warm Octane worker outlives any single
        // invocation's view of the environment.
        $address = getenv('AWS_XRAY_DAEMON_ADDRESS')
            ?: getenv('_AWS_XRAY_DAEMON_ADDRESS')
            ?: ($configured ?: '127.0.0.1:2000');

        $parts = explode(':', $address);

        return new self(
            serializer: $serializer,
            host: $parts[0] ?: '127.0.0.1',
            port: isset($parts[1]) ? (int) $parts[1] : 2000,
            logger: $logger,
        );
    }

    public function emit(array $spans): void
    {
        foreach ($spans as $span) {
            $this->send($span);
        }
    }

    private function send(Span $span): void
    {
        try {
            $document = json_encode(
                $this->serializer->serialize($span),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $e) {
            $this->logger?->warning('xray: could not encode segment', ['exception' => $e]);

            return;
        }

        if (strlen($document) > self::MAX_SEGMENT_SIZE) {
            $this->logger?->warning('xray: segment exceeds 64KB and was dropped', [
                'name' => $span->name,
                'bytes' => strlen($document),
            ]);

            return;
        }

        $this->write(self::HEADER."\n".$document);
    }

    private function write(string $packet): void
    {
        $socket = $this->socket();

        if ($socket === false) {
            return;
        }

        try {
            // Suppressed: a failed trace write must never surface as a warning
            // in an application's error handler.
            @socket_sendto($socket, $packet, strlen($packet), 0, $this->host, $this->port);
        } catch (Throwable $e) {
            $this->logger?->debug('xray: udp send failed', ['exception' => $e]);
        }
    }

    /**
     * One socket for the life of the worker. Creating a socket per segment —
     * as some implementations do — wastes a syscall pair on every span.
     */
    private function socket(): Socket|false
    {
        if ($this->socket !== null) {
            return $this->socket;
        }

        if (! function_exists('socket_create')) {
            $this->logger?->warning('xray: ext-sockets is not installed, udp emitter disabled');

            return $this->socket = false;
        }

        $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

        if ($socket === false) {
            $this->logger?->warning('xray: could not create udp socket');
        }

        return $this->socket = $socket;
    }

    public function flush(): void
    {
        // Nothing is buffered; datagrams leave on emit().
    }

    public function isAvailable(): bool
    {
        if (! function_exists('socket_create')) {
            return false;
        }

        return getenv('AWS_XRAY_DAEMON_ADDRESS') !== false
            || getenv('_AWS_XRAY_DAEMON_ADDRESS') !== false;
    }

    public function requiresTracingMode(): string
    {
        return 'Active';
    }

    public function __destruct()
    {
        if ($this->socket instanceof Socket) {
            @socket_close($this->socket);
        }
    }
}
