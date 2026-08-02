<?php

declare(strict_types=1);

use Atymic\Xray\Serializer\OtlpSerializer;
use Atymic\Xray\Trace\Span;
use Atymic\Xray\Trace\SpanKind;
use Atymic\Xray\Trace\SpanStatus;

function otlpSpan(
    string $name = 'test',
    SpanKind $kind = SpanKind::Server,
    string $traceId = '1-5759e988-bd862e3fe1be46a994272793',
    ?string $parentSpanId = null,
): Span {
    return new Span(
        name: $name,
        traceId: $traceId,
        spanId: '53995c3f42cd8ad8',
        parentSpanId: $parentSpanId,
        kind: $kind,
        startTime: 1480615200.0,
        isRoot: true,
    );
}

beforeEach(function (): void {
    $this->serializer = new OtlpSerializer('test-service');
});

it('produces the OTLP envelope', function (): void {
    $payload = $this->serializer->serialize([otlpSpan()->end(1480615200.5)]);

    expect($payload)->toHaveKey('resourceSpans')
        ->and($payload['resourceSpans'][0])->toHaveKeys(['resource', 'scopeSpans'])
        ->and($payload['resourceSpans'][0]['scopeSpans'][0]['spans'])->toHaveCount(1);
});

it('carries the service name as a resource attribute', function (): void {
    $payload = $this->serializer->serialize([otlpSpan()->end()]);

    expect($payload['resourceSpans'][0]['resource']['attributes'])
        ->toContain(['key' => 'service.name', 'value' => ['stringValue' => 'test-service']]);
});

it('flattens the trace id to W3C form', function (): void {
    // Same 128 bits as the X-Ray id; the hyphens are presentation only, which
    // is what lets a trace survive a change of emitter.
    $span = $this->serializer->serialize([otlpSpan()->end()])['resourceSpans'][0]['scopeSpans'][0]['spans'][0];

    expect($span['traceId'])->toBe('5759e988bd862e3fe1be46a994272793')
        ->and($span['spanId'])->toBe('53995c3f42cd8ad8');
});

it('drops a span whose trace id cannot be correlated', function (): void {
    $payload = $this->serializer->serialize([otlpSpan(traceId: 'garbage')->end()]);

    expect($payload['resourceSpans'][0]['scopeSpans'][0]['spans'])->toBeEmpty();
});

it('encodes timestamps as nanosecond strings', function (): void {
    // JSON numbers cannot carry 64-bit values losslessly, so OTLP/JSON uses
    // strings.
    $span = $this->serializer->serialize([otlpSpan()->end(1480615200.5)])['resourceSpans'][0]['scopeSpans'][0]['spans'][0];

    expect($span['startTimeUnixNano'])->toBe('1480615200000000000')
        ->and($span['endTimeUnixNano'])->toBe('1480615200500000000');
});

it('maps span kind onto the wire enum', function (SpanKind $kind, int $expected): void {
    $span = $this->serializer->serialize([otlpSpan(kind: $kind)->end()])['resourceSpans'][0]['scopeSpans'][0]['spans'][0];

    expect($span['kind'])->toBe($expected);
})->with([
    [SpanKind::Internal, 1],
    [SpanKind::Server, 2],
    [SpanKind::Client, 3],
    [SpanKind::Producer, 4],
    [SpanKind::Consumer, 5],
]);

it('collapses the three X-Ray failure kinds but keeps which one it was', function (SpanStatus $status): void {
    $span = $this->serializer->serialize([otlpSpan()->setStatus($status)->end()])['resourceSpans'][0]['scopeSpans'][0]['spans'][0];

    expect($span['status']['code'])->toBe(2)
        ->and($span['status']['message'])->toBe($status->value);
})->with([SpanStatus::Error, SpanStatus::Fault, SpanStatus::Throttle]);

it('types attributes for the wire', function (): void {
    $span = $this->serializer->serialize([
        otlpSpan()->setAttributes([
            'string' => 'value',
            'int' => 42,
            'float' => 1.5,
            'bool' => true,
        ])->end(),
    ])['resourceSpans'][0]['scopeSpans'][0]['spans'][0];

    $byKey = array_column($span['attributes'], 'value', 'key');

    expect($byKey['string'])->toBe(['stringValue' => 'value'])
        ->and($byKey['int'])->toBe(['intValue' => '42'])
        ->and($byKey['float'])->toBe(['doubleValue' => 1.5])
        ->and($byKey['bool'])->toBe(['boolValue' => true]);
});

it('records exceptions as OTLP events', function (): void {
    $span = $this->serializer->serialize([
        otlpSpan()->recordException(new RuntimeException('it broke'))->end(),
    ])['resourceSpans'][0]['scopeSpans'][0]['spans'][0];

    $event = $span['events'][0];
    $attributes = array_column($event['attributes'], 'value', 'key');

    expect($event['name'])->toBe('exception')
        ->and($attributes['exception.type'])->toBe(['stringValue' => RuntimeException::class])
        ->and($attributes['exception.message'])->toBe(['stringValue' => 'it broke']);
});

it('includes the parent when nested', function (): void {
    $span = $this->serializer->serialize([
        otlpSpan(parentSpanId: 'aaaaaaaaaaaaaaaa')->end(),
    ])['resourceSpans'][0]['scopeSpans'][0]['spans'][0];

    expect($span['parentSpanId'])->toBe('aaaaaaaaaaaaaaaa');
});

it('omits the parent for a root span', function (): void {
    $span = $this->serializer->serialize([otlpSpan()->end()])['resourceSpans'][0]['scopeSpans'][0]['spans'][0];

    expect($span)->not->toHaveKey('parentSpanId');
});

it('serializes to valid json', function (): void {
    expect(json_encode($this->serializer->serialize([otlpSpan()->end()]), JSON_THROW_ON_ERROR))
        ->toBeString();
});
