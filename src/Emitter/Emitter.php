<?php

declare(strict_types=1);

namespace Atymic\Xray\Emitter;

use Atymic\Xray\Trace\Span;

/**
 * Where finished spans go.
 *
 * Three implementations ship, all terminating at CloudWatch Transaction Search
 * (X-Ray traces are converted to OpenTelemetry semantic-convention format on
 * ingest, so both wire formats land in the same `aws/spans` log group and are
 * queried the same way):
 *
 *   - {@see UdpDaemonEmitter}  X-Ray segment JSON over UDP to the Lambda daemon
 *   - {@see CollectorEmitter}  OTLP to a local collector (Rotel) on localhost
 *   - {@see OtlpEmitter}       OTLP to the X-Ray endpoint, SigV4-signed
 *
 * Implementations must never throw. Tracing is not worth failing a request
 * over, so transport problems are swallowed (and optionally logged).
 */
interface Emitter
{
    /**
     * @param  list<Span>  $spans
     */
    public function emit(array $spans): void;

    /**
     * Push anything buffered. Called from `RequestTerminated`, after the
     * response has already gone to the client.
     */
    public function flush(): void;

    /**
     * Whether this emitter can actually deliver in the current environment —
     * e.g. the UDP emitter needs `AWS_XRAY_DAEMON_ADDRESS`, which only exists
     * when the function has `tracing: Active`.
     *
     * Drives `emitter => auto` resolution, and stops us firing datagrams into
     * a void when no daemon is listening.
     */
    public function isAvailable(): bool;

    /**
     * The Lambda tracing mode this emitter requires, if any. Used to warn on a
     * misconfigured deployment rather than silently producing no traces.
     */
    public function requiresTracingMode(): ?string;
}
