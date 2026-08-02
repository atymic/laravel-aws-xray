<?php

declare(strict_types=1);

namespace Atymic\Xray\Trace;

use Random\RandomException;

/**
 * Generates X-Ray-format identifiers.
 *
 * Trace IDs are always generated in X-Ray format (`1-<8 hex epoch>-<24 hex
 * random>`) even when the OTLP emitter is active. The two formats describe the
 * same 128 bits — the hyphens are presentation — so an X-Ray ID can always be
 * flattened for OTLP, while a random W3C ID cannot reliably be widened back
 * into a valid X-Ray one. Generating in the stricter format keeps a single ID
 * space across all three emitters, so a trace started under one still stitches
 * under another.
 *
 * @see https://docs.aws.amazon.com/xray/latest/devguide/xray-api-segmentdocuments.html
 */
final class IdGenerator
{
    /**
     * @throws RandomException
     */
    public function traceId(?int $timestamp = null): string
    {
        return sprintf(
            '1-%08x-%s',
            $timestamp ?? time(),
            bin2hex(random_bytes(12)),
        );
    }

    /**
     * A 64-bit segment/subsegment id as 16 hex digits.
     *
     * @throws RandomException
     */
    public function spanId(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * Flatten an X-Ray trace id to the 32-hex-digit W3C form used on the wire
     * by OTLP. Returns null when the input is not a well-formed X-Ray id.
     */
    public function toW3C(string $xrayTraceId): ?string
    {
        if (! self::isValidTraceId($xrayTraceId)) {
            return null;
        }

        [, $epoch, $unique] = explode('-', $xrayTraceId);

        return $epoch.$unique;
    }

    /**
     * Widen a 32-hex-digit W3C trace id into X-Ray format.
     *
     * AWS confirmed (Oct 2023) that the epoch prefix "isn't required when
     * sending W3C trace IDs in X-Ray format", so we do not validate that the
     * leading 8 digits decode to a plausible time.
     */
    public function fromW3C(string $w3cTraceId): ?string
    {
        if (preg_match('/^[0-9a-f]{32}$/', $w3cTraceId) !== 1) {
            return null;
        }

        return sprintf('1-%s-%s', substr($w3cTraceId, 0, 8), substr($w3cTraceId, 8));
    }

    public static function isValidTraceId(string $traceId): bool
    {
        return preg_match('/^1-[0-9a-f]{8}-[0-9a-f]{24}$/', $traceId) === 1;
    }

    public static function isValidSpanId(string $spanId): bool
    {
        return preg_match('/^[0-9a-f]{16}$/', $spanId) === 1;
    }
}
