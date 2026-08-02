<?php

declare(strict_types=1);

use Atymic\Xray\Http\SigV4Signer;

it('produces an Authorization header in SigV4 form', function (): void {
    $signer = new SigV4Signer('us-east-1', 'AKIAIOSFODNN7EXAMPLE', 'secret');

    $headers = $signer->headers(
        method: 'POST',
        url: 'https://xray.us-east-1.amazonaws.com/v1/traces',
        body: '{}',
        headers: ['Content-Type' => 'application/json'],
    );

    expect($headers['Authorization'])
        ->toStartWith('AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/')
        // The signing service for this endpoint is `xray`, per AWS's own
        // collector configuration.
        ->toContain('/us-east-1/xray/aws4_request')
        ->toContain('SignedHeaders=')
        ->toMatch('/Signature=[0-9a-f]{64}$/');
});

it('signs the exact payload', function (): void {
    $signer = new SigV4Signer('us-east-1', 'key', 'secret');

    expect($signer->headers('POST', 'https://xray.us-east-1.amazonaws.com/v1/traces', 'body')['X-Amz-Content-Sha256'])
        ->toBe(hash('sha256', 'body'));
});

it('produces a different signature for a different body', function (): void {
    // Guards the property that makes compression-before-signing mandatory.
    $signer = new SigV4Signer('us-east-1', 'key', 'secret');
    $url = 'https://xray.us-east-1.amazonaws.com/v1/traces';

    $first = $signer->headers('POST', $url, '{"a":1}')['Authorization'];
    $second = $signer->headers('POST', $url, '{"a":2}')['Authorization'];

    expect($first)->not->toBe($second);
});

it('includes the session token when one is present', function (): void {
    // Lambda supplies temporary credentials, so this is the normal case there.
    $signer = new SigV4Signer('us-east-1', 'key', 'secret', 'session-token');

    $headers = $signer->headers('POST', 'https://xray.us-east-1.amazonaws.com/v1/traces', '{}');

    expect($headers['X-Amz-Security-Token'])->toBe('session-token')
        ->and($headers['Authorization'])->toContain('x-amz-security-token');
});

it('signs the host and date headers', function (): void {
    $signer = new SigV4Signer('us-east-1', 'key', 'secret');

    $headers = $signer->headers('POST', 'https://xray.us-east-1.amazonaws.com/v1/traces', '{}');

    expect($headers['Host'])->toBe('xray.us-east-1.amazonaws.com')
        ->and($headers['X-Amz-Date'])->toMatch('/^\d{8}T\d{6}Z$/')
        ->and($headers['Authorization'])->toContain('host')
        ->and($headers['Authorization'])->toContain('x-amz-date');
});

it('scopes the signature to the configured region', function (): void {
    $signer = new SigV4Signer('ap-southeast-2', 'key', 'secret');

    expect($signer->headers('POST', 'https://xray.ap-southeast-2.amazonaws.com/v1/traces', '{}')['Authorization'])
        ->toContain('/ap-southeast-2/xray/aws4_request');
});

it('passes headers through unsigned when no credentials resolve', function (): void {
    foreach (['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_SESSION_TOKEN'] as $key) {
        putenv($key);
    }

    $signer = new SigV4Signer('us-east-1');

    // Only meaningful when the SDK's provider chain also finds nothing.
    if ($signer->hasCredentials()) {
        $this->markTestSkipped('ambient AWS credentials are available');
    }

    expect($signer->headers('POST', 'https://xray.us-east-1.amazonaws.com/v1/traces', '{}'))
        ->not->toHaveKey('Authorization');
});

it('reads credentials from the environment', function (): void {
    putenv('AWS_ACCESS_KEY_ID=AKIAENVEXAMPLE');
    putenv('AWS_SECRET_ACCESS_KEY=envsecret');

    $signer = new SigV4Signer('us-east-1');

    expect($signer->hasCredentials())->toBeTrue()
        ->and($signer->headers('POST', 'https://xray.us-east-1.amazonaws.com/v1/traces', '{}')['Authorization'])
        ->toContain('AKIAENVEXAMPLE');

    putenv('AWS_ACCESS_KEY_ID');
    putenv('AWS_SECRET_ACCESS_KEY');
});
