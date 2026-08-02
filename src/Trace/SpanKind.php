<?php

declare(strict_types=1);

namespace Atymic\Xray\Trace;

/**
 * Span kind, following OpenTelemetry semantics.
 *
 * The X-Ray serializer maps these onto segment `namespace` values; the OTLP
 * serializer maps them onto the wire enum directly.
 */
enum SpanKind: string
{
    /** An incoming request handled by this service. */
    case Server = 'server';

    /** An outgoing call to something else (HTTP, DB, cache). */
    case Client = 'client';

    /** A message pushed onto a queue. */
    case Producer = 'producer';

    /** A message consumed from a queue. */
    case Consumer = 'consumer';

    /** Work happening inside this service with no remote boundary. */
    case Internal = 'internal';

    /**
     * OTLP wire representation.
     *
     * @see https://opentelemetry.io/docs/specs/otel/trace/api/#spankind
     */
    public function toOtlp(): int
    {
        return match ($this) {
            self::Internal => 1,
            self::Server => 2,
            self::Client => 3,
            self::Producer => 4,
            self::Consumer => 5,
        };
    }

    /**
     * X-Ray subsegment `namespace`, or null when the concept does not apply.
     *
     * X-Ray only understands two namespaces: `aws` for AWS SDK calls and
     * `remote` for everything else downstream. A namespace on a purely local
     * subsegment makes X-Ray infer a bogus downstream node on the service map,
     * so internal/server spans deliberately return null.
     */
    public function toXrayNamespace(bool $isAws = false): ?string
    {
        if ($isAws) {
            return 'aws';
        }

        return match ($this) {
            self::Client, self::Producer => 'remote',
            self::Server, self::Consumer, self::Internal => null,
        };
    }
}
