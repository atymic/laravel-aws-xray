<?php

declare(strict_types=1);

use Atymic\Xray\Emitter\UdpDaemonEmitter;
use Atymic\Xray\Serializer\XraySegmentSerializer;
use Atymic\Xray\Trace\Span;
use Atymic\Xray\Trace\SpanKind;
use Psr\Log\AbstractLogger;

function udpSpan(string $name = 'test'): Span
{
    return (new Span(
        name: $name,
        traceId: '1-5759e988-bd862e3fe1be46a994272793',
        spanId: '53995c3f42cd8ad8',
        parentSpanId: null,
        kind: SpanKind::Server,
        startTime: 1480615200.0,
        isRoot: true,
    ))->end(1480615200.5);
}

beforeEach(function (): void {
    if (! function_exists('socket_create')) {
        $this->markTestSkipped('ext-sockets not available');
    }
});

it('sends a datagram the daemon would accept', function (): void {
    // A real socket round trip: the framing is the part most easily got wrong,
    // and a wrong header is silently discarded by the daemon.
    $listener = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    socket_bind($listener, '127.0.0.1', 0);
    socket_getsockname($listener, $address, $port);
    socket_set_option($listener, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 2, 'usec' => 0]);

    $emitter = new UdpDaemonEmitter(
        serializer: new XraySegmentSerializer('test-service'),
        host: '127.0.0.1',
        port: $port,
    );

    $emitter->emit([udpSpan()]);

    $buffer = '';
    socket_recvfrom($listener, $buffer, 65535, 0, $from, $fromPort);
    socket_close($listener);

    [$header, $document] = explode("\n", $buffer, 2);

    expect(json_decode($header, true))->toBe(['format' => 'json', 'version' => 1]);

    $segment = json_decode($document, true);

    expect($segment)->toMatchArray([
        'id' => '53995c3f42cd8ad8',
        'trace_id' => '1-5759e988-bd862e3fe1be46a994272793',
        'name' => 'test-service',
        'start_time' => 1480615200.0,
        'end_time' => 1480615200.5,
    ]);
});

it('sends one datagram per span', function (): void {
    $listener = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    socket_bind($listener, '127.0.0.1', 0);
    socket_getsockname($listener, $address, $port);
    socket_set_option($listener, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 2, 'usec' => 0]);

    $emitter = new UdpDaemonEmitter(new XraySegmentSerializer('test-service'), '127.0.0.1', $port);
    $emitter->emit([udpSpan('one'), udpSpan('two')]);

    $received = 0;

    while ($received < 2) {
        $buffer = '';

        if (socket_recvfrom($listener, $buffer, 65535, 0, $from, $fromPort) === false) {
            break;
        }

        $received++;
    }

    socket_close($listener);

    expect($received)->toBe(2);
});

it('drops an oversized segment instead of sending a truncated one', function (): void {
    // The daemon's limit is 64KB; a truncated datagram is invalid JSON and
    // would be discarded anyway, so dropping it explicitly is honest.
    $logger = new class extends AbstractLogger
    {
        public array $records = [];

        public function log($level, $message, array $context = []): void
        {
            $this->records[] = [$level, (string) $message];
        }
    };

    $emitter = new UdpDaemonEmitter(
        serializer: new XraySegmentSerializer('test-service'),
        host: '127.0.0.1',
        port: 65000,
        logger: $logger,
    );

    $emitter->emit([udpSpan()->setAttribute('huge', str_repeat('x', 70_000))]);

    expect($logger->records)->toHaveCount(1)
        ->and($logger->records[0][1])->toContain('64KB');
});

it('never throws when the daemon is unreachable', function (): void {
    // Tracing must not be able to fail a request.
    $emitter = new UdpDaemonEmitter(new XraySegmentSerializer('test'), '127.0.0.1', 1);

    $emitter->emit([udpSpan()]);
    $emitter->flush();
})->throwsNoExceptions();

it('reports availability from the daemon address', function (): void {
    $emitter = new UdpDaemonEmitter(new XraySegmentSerializer('test'));

    putenv('AWS_XRAY_DAEMON_ADDRESS');
    expect($emitter->isAvailable())->toBeFalse();

    putenv('AWS_XRAY_DAEMON_ADDRESS=127.0.0.1:2000');
    expect($emitter->isAvailable())->toBeTrue();

    putenv('AWS_XRAY_DAEMON_ADDRESS');
});

it('requires active tracing', function (): void {
    // Under PassThrough there is no daemon, so this emitter cannot deliver.
    expect((new UdpDaemonEmitter(new XraySegmentSerializer('test')))->requiresTracingMode())
        ->toBe('Active');
});

it('reads the daemon address from the environment', function (): void {
    putenv('AWS_XRAY_DAEMON_ADDRESS=10.0.0.5:3000');

    $emitter = UdpDaemonEmitter::fromEnvironment(new XraySegmentSerializer('test'));

    $host = new ReflectionProperty($emitter, 'host');
    $port = new ReflectionProperty($emitter, 'port');

    expect($host->getValue($emitter))->toBe('10.0.0.5')
        ->and($port->getValue($emitter))->toBe(3000);

    putenv('AWS_XRAY_DAEMON_ADDRESS');
});
