<?php

declare(strict_types=1);

namespace Atymic\Xray\Emitter;

use Atymic\Xray\Serializer\OtlpSerializer;
use JsonException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Posts OTLP to a collector running beside us in the execution environment —
 * typically Rotel, baked into the image as a Lambda extension.
 *
 * The write is loopback, unencrypted and unsigned, so it costs a fraction of a
 * millisecond; the collector then signs and forwards to AWS on its own
 * schedule. Because a Lambda extension keeps running briefly after the handler
 * returns, that forwarding happens off the request path entirely — which is
 * what makes 100% sampling affordable without paying for it in latency.
 *
 * Unlike the UDP emitter this does not need `tracing: Active`, so sampling
 * stays ours to decide.
 *
 * @see https://github.com/streamfold/rotel-lambda-extension
 */
final class CollectorEmitter implements Emitter
{
    /** @var list<array<string, mixed>> */
    private array $buffer = [];

    private readonly OtlpLimits $limits;

    public function __construct(
        OtlpSerializer $serializer,
        private readonly string $endpoint = 'http://127.0.0.1:4318/v1/traces',
        private readonly float $timeout = 1.0,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->limits = new OtlpLimits($serializer, $logger);
    }

    /**
     * @param  string|null  $configured  `xray.collector.endpoint`. The standard
     *                                   OTEL_* variables win when present, since
     *                                   that is what a collector sets.
     * @param  float|null  $timeout  `xray.collector.timeout`.
     */
    public static function fromEnvironment(
        OtlpSerializer $serializer,
        ?LoggerInterface $logger = null,
        ?string $configured = null,
        ?float $timeout = null,
    ): self {
        $base = getenv('OTEL_EXPORTER_OTLP_TRACES_ENDPOINT')
            ?: getenv('OTEL_EXPORTER_OTLP_ENDPOINT')
            ?: ($configured ?: 'http://127.0.0.1:4318');

        $endpoint = str_contains($base, '/v1/traces')
            ? $base
            : rtrim($base, '/').'/v1/traces';

        return new self($serializer, $endpoint, $timeout ?? 1.0, $logger);
    }

    public function emit(array $spans): void
    {
        if ($spans === []) {
            return;
        }

        // Buffer so a request produces one round trip rather than one per span.
        // Limits are applied here rather than at flush: a collector accepts a
        // payload AWS will later reject, and by then nothing is left to log it.
        foreach ($this->limits->payloads($spans) as $payload) {
            $this->buffer[] = $payload;
        }
    }

    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $payloads = $this->buffer;
        $this->buffer = [];

        foreach ($payloads as $payload) {
            $this->post($payload);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function post(array $payload): void
    {
        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            $this->logger?->warning('xray: could not encode otlp payload', ['exception' => $e]);

            return;
        }

        try {
            $handle = curl_init($this->endpoint);

            if ($handle === false) {
                return;
            }

            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS => (int) ($this->timeout * 1000),
                CURLOPT_CONNECTTIMEOUT_MS => 200,
            ]);

            $response = curl_exec($handle);
            $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $error = curl_error($handle);

            if ($response === false || $status >= 400) {
                $this->logger?->debug('xray: collector rejected spans', [
                    'status' => $status,
                    'error' => $error,
                ]);
            }
        } catch (Throwable $e) {
            $this->logger?->debug('xray: collector post failed', ['exception' => $e]);
        }
    }

    public function isAvailable(): bool
    {
        if (! function_exists('curl_init')) {
            return false;
        }

        return getenv('OTEL_EXPORTER_OTLP_ENDPOINT') !== false
            || getenv('OTEL_EXPORTER_OTLP_TRACES_ENDPOINT') !== false;
    }

    public function requiresTracingMode(): ?string
    {
        return null;
    }
}
