<?php

declare(strict_types=1);

namespace Atymic\Xray\Http;

use Aws\Credentials\CredentialProvider;
use Aws\Credentials\Credentials;
use Throwable;

/**
 * Signs requests to the X-Ray OTLP endpoint with AWS Signature Version 4.
 *
 * Implemented directly rather than through the AWS SDK's signer so the package
 * has no hard dependency on `aws/aws-sdk-php` — the SDK is used only to resolve
 * credentials, and only when it happens to be installed. On Lambda, credentials
 * arrive as environment variables anyway, so the common case needs nothing.
 *
 * The signing service is `xray`, matching AWS's own collector configuration for
 * this endpoint.
 *
 * @see https://docs.aws.amazon.com/IAM/latest/UserGuide/reference_sigv.html
 */
final class SigV4Signer
{
    private const ALGORITHM = 'AWS4-HMAC-SHA256';

    private const SERVICE = 'xray';

    private ?string $accessKey = null;

    private ?string $secretKey = null;

    private ?string $sessionToken = null;

    /**
     * When the SDK-chain credentials stop being valid. Null means they carry no
     * expiry (long-lived keys), in which case they are resolved once.
     */
    private ?int $expiresAt = null;

    /** Whether the SDK chain has been consulted at least once. */
    private bool $resolved = false;

    public function __construct(
        private readonly string $region,
        ?string $accessKey = null,
        ?string $secretKey = null,
        ?string $sessionToken = null,
    ) {
        if ($accessKey !== null && $secretKey !== null) {
            $this->accessKey = $accessKey;
            $this->secretKey = $secretKey;
            $this->sessionToken = $sessionToken;
            $this->resolved = true;
        }
    }

    /**
     * Signed headers for a request.
     *
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    public function headers(string $method, string $url, string $body, array $headers = []): array
    {
        $this->resolveCredentials();

        if ($this->accessKey === null || $this->secretKey === null) {
            return $headers;
        }

        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        $path = $parts['path'] ?? '/';
        $query = $parts['query'] ?? '';

        $timestamp = gmdate('Ymd\THis\Z');
        $date = substr($timestamp, 0, 8);

        $headers['Host'] = $host;
        $headers['X-Amz-Date'] = $timestamp;

        if ($this->sessionToken !== null) {
            $headers['X-Amz-Security-Token'] = $this->sessionToken;
        }

        $payloadHash = hash('sha256', $body);
        $headers['X-Amz-Content-Sha256'] = $payloadHash;

        // Canonical headers: lowercased names, sorted, values trimmed.
        $canonical = [];

        foreach ($headers as $name => $value) {
            $canonical[strtolower($name)] = trim($value);
        }

        ksort($canonical);

        $signedHeaders = implode(';', array_keys($canonical));

        $canonicalHeaders = '';

        foreach ($canonical as $name => $value) {
            $canonicalHeaders .= $name.':'.$value."\n";
        }

        $canonicalRequest = implode("\n", [
            $method,
            $path,
            $query,
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $scope = implode('/', [$date, $this->region, self::SERVICE, 'aws4_request']);

        $stringToSign = implode("\n", [
            self::ALGORITHM,
            $timestamp,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $signature = bin2hex(hash_hmac('sha256', $stringToSign, $this->signingKey($date), true));

        $headers['Authorization'] = sprintf(
            '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            self::ALGORITHM,
            $this->accessKey,
            $scope,
            $signedHeaders,
            $signature,
        );

        return $headers;
    }

    private function signingKey(string $date): string
    {
        $key = hash_hmac('sha256', $date, 'AWS4'.$this->secretKey, true);
        $key = hash_hmac('sha256', $this->region, $key, true);
        $key = hash_hmac('sha256', self::SERVICE, $key, true);

        return hash_hmac('sha256', 'aws4_request', $key, true);
    }

    public function hasCredentials(): bool
    {
        $this->resolveCredentials();

        return $this->accessKey !== null && $this->secretKey !== null;
    }

    /**
     * Environment first — that is how Lambda supplies them, and it avoids
     * loading the SDK's provider chain on the hot path. Falls back to the SDK
     * chain (profiles, IMDS, SSO) only when it is installed.
     *
     * Never cached across the process lifetime. The signer lives in a singleton
     * that outlives the credentials it signs with: Lambda rotates the
     * environment variables between invocations, and SDK-chain credentials
     * carry an expiry. Caching once at boot means every request after the first
     * rotation signs with stale keys and is rejected 403 for the remaining life
     * of the worker — visible only as a warning in the log.
     */
    private function resolveCredentials(): void
    {
        $accessKey = getenv('AWS_ACCESS_KEY_ID');
        $secretKey = getenv('AWS_SECRET_ACCESS_KEY');

        if (is_string($accessKey) && is_string($secretKey) && $accessKey !== '' && $secretKey !== '') {
            $this->accessKey = $accessKey;
            $this->secretKey = $secretKey;
            $token = getenv('AWS_SESSION_TOKEN');
            $this->sessionToken = is_string($token) && $token !== '' ? $token : null;

            return;
        }

        if (! class_exists(CredentialProvider::class)) {
            return;
        }

        // The SDK chain is the expensive path (profiles, IMDS, SSO), so its
        // result is held until shortly before it expires rather than being
        // re-resolved per request. Credentials with no expiry are resolved once.
        if ($this->resolved && $this->accessKey !== null
            && ($this->expiresAt === null || $this->expiresAt > time() + 60)) {
            return;
        }

        $this->resolved = true;

        try {
            /** @var Credentials $credentials */
            $credentials = call_user_func(CredentialProvider::defaultProvider())->wait();

            $this->accessKey = $credentials->getAccessKeyId();
            $this->secretKey = $credentials->getSecretKey();
            $this->sessionToken = $credentials->getSecurityToken();
            $this->expiresAt = $credentials->getExpiration();
        } catch (Throwable) {
            // No credentials available; the emitter reports itself unavailable.
        }
    }
}
