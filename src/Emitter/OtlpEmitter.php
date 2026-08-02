<?php

declare(strict_types=1);

namespace Atymic\Xray\Emitter;

use Atymic\Xray\Http\SigV4Signer;
use Atymic\Xray\Serializer\OtlpSerializer;
use JsonException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Posts OTLP directly to the X-Ray OTLP endpoint, SigV4-signed.
 *
 * The only emitter that needs nothing alongside it — no daemon, no extension —
 * so it is what makes the package work outside Lambda (local development, EC2,
 * containers) as well as in it.
 *
 * The cost is that it is the one emitter that must complete before the handler
 * returns: Lambda freezes the execution environment on return, so an in-flight
 * request would simply never finish. That makes it the slowest of the three on
 * the request path, and the reason the other two exist.
 *
 * Requires CloudWatch Transaction Search to be enabled on the account and
 * region; without it the endpoint rejects spans outright rather than falling
 * back to classic X-Ray.
 *
 * @see https://docs.aws.amazon.com/AmazonCloudWatch/latest/monitoring/CloudWatch-OTLPEndpoint.html
 */
final class OtlpEmitter implements Emitter
{
    /** @var list<array<string, mixed>> */
    private array $buffer = [];

    private readonly OtlpLimits $limits;

    public function __construct(
        OtlpSerializer $serializer,
        private readonly SigV4Signer $signer,
        private readonly string $region,
        private readonly ?string $endpoint = null,
        private readonly float $timeout = 3.0,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->limits = new OtlpLimits($serializer, $logger);
    }

    public function emit(array $spans): void
    {
        if ($spans === []) {
            return;
        }

        // The endpoint rejects the entire request when any limit is exceeded,
        // so one oversized span would otherwise discard the whole trace.
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

    private function url(): string
    {
        return $this->endpoint ?? sprintf('https://xray.%s.amazonaws.com/v1/traces', $this->region);
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
            // Signing hashes the exact bytes on the wire, so anything that
            // would alter the body (compression) has to happen before this.
            $headers = $this->signer->headers(
                method: 'POST',
                url: $this->url(),
                body: $body,
                headers: ['Content-Type' => 'application/json'],
            );

            $handle = curl_init($this->url());

            if ($handle === false) {
                return;
            }

            $curlHeaders = [];

            foreach ($headers as $name => $value) {
                $curlHeaders[] = $name.': '.$value;
            }

            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $curlHeaders,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS => (int) ($this->timeout * 1000),
            ]);

            $response = curl_exec($handle);
            $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

            if ($response === false || $status >= 400) {
                $this->logger?->warning('xray: otlp endpoint rejected spans', [
                    'status' => $status,
                    // The body names the cause — most often Transaction Search
                    // not being enabled — so it is worth surfacing.
                    'response' => is_string($response) ? mb_substr($response, 0, 500) : null,
                ]);
            }
        } catch (Throwable $e) {
            $this->logger?->warning('xray: otlp post failed', ['exception' => $e]);
        }
    }

    public function isAvailable(): bool
    {
        return function_exists('curl_init') && $this->signer->hasCredentials();
    }

    public function requiresTracingMode(): ?string
    {
        return null;
    }
}
