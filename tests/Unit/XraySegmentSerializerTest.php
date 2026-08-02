<?php

declare(strict_types=1);

use Atymic\Xray\Serializer\XraySegmentSerializer;
use Atymic\Xray\Trace\IdGenerator;
use Atymic\Xray\Trace\Span;
use Atymic\Xray\Trace\SpanKind;
use Atymic\Xray\Trace\SpanStatus;

function span(
    string $name = 'test',
    SpanKind $kind = SpanKind::Internal,
    bool $isRoot = false,
    ?string $parentSpanId = null,
): Span {
    return new Span(
        name: $name,
        traceId: '1-5759e988-bd862e3fe1be46a994272793',
        spanId: '53995c3f42cd8ad8',
        parentSpanId: $parentSpanId,
        kind: $kind,
        startTime: 1480615200.5,
        isRoot: $isRoot,
    );
}

beforeEach(function (): void {
    $this->serializer = new XraySegmentSerializer('my-service');
});

it('produces the documented required fields', function (): void {
    $document = $this->serializer->serialize(span()->end(1480615201.0));

    expect($document)->toHaveKeys(['id', 'trace_id', 'name', 'start_time', 'end_time'])
        ->and($document['id'])->toBe('53995c3f42cd8ad8')
        ->and($document['trace_id'])->toBe('1-5759e988-bd862e3fe1be46a994272793')
        ->and($document['start_time'])->toBe(1480615200.5)
        ->and($document['end_time'])->toBe(1480615201.0);
});

it('marks an unfinished span in_progress instead of guessing an end time', function (): void {
    $document = $this->serializer->serialize(span());

    expect($document)->toHaveKey('in_progress')
        ->and($document['in_progress'])->toBeTrue()
        ->and($document)->not->toHaveKey('end_time');
});

it('declares itself a subsegment unless it owns the segment', function (): void {
    // Under Lambda `tracing: Active` the function segment belongs to the
    // runtime, so everything we emit has to say what it is — and a standalone
    // subsegment is only attachable when it names a parent.
    $child = span(parentSpanId: 'defdfd9912dc5a56');

    expect($this->serializer->serialize($child->end()))->toHaveKey('type')
        ->and($this->serializer->serialize($child->end())['type'])->toBe('subsegment')
        ->and($this->serializer->serialize(span(isRoot: true)->end()))->not->toHaveKey('type');
});

it('names a root server segment for the service so the map has one node', function (): void {
    $document = $this->serializer->serialize(
        span(name: 'GET /users', kind: SpanKind::Server, isRoot: true)->end(),
    );

    expect($document['name'])->toBe('my-service');
});

it('names a subsegment for the operation', function (): void {
    expect($this->serializer->serialize(span(name: 'SELECT users')->end())['name'])
        ->toBe('SELECT users');
});

it('builds the http block from semantic convention attributes', function (): void {
    $span = span(kind: SpanKind::Server, isRoot: true)
        ->setAttributes([
            'http.request.method' => 'GET',
            'url.full' => 'https://example.com/users',
            'http.response.status_code' => 200,
            'client.address' => '203.0.113.1',
            'user_agent.original' => 'curl/8.0',
        ])
        ->end();

    $document = $this->serializer->serialize($span);

    expect($document['http']['request'])->toMatchArray([
        'method' => 'GET',
        'url' => 'https://example.com/users',
        'client_ip' => '203.0.113.1',
        'user_agent' => 'curl/8.0',
    ])->and($document['http']['response']['status'])->toBe(200);
});

it('builds the sql block only for database spans', function (): void {
    $span = span(kind: SpanKind::Client)
        ->setAttributes([
            'db.system.name' => 'mysql',
            'db.namespace' => 'app',
            'db.query.text' => 'select * from users where id = ?',
        ])
        ->end();

    $document = $this->serializer->serialize($span);

    expect($document['sql'])->toMatchArray([
        'database_type' => 'mysql',
        'url' => 'app',
        'sanitized_query' => 'select * from users where id = ?',
    ]);

    expect($this->serializer->serialize(span()->end()))->not->toHaveKey('sql');
});

it('marks downstream calls remote but leaves local work unnamespaced', function (): void {
    // A namespace on a purely local subsegment makes X-Ray infer a phantom
    // downstream node on the service map.
    expect($this->serializer->serialize(span(kind: SpanKind::Client)->end())['namespace'])
        ->toBe('remote')
        ->and($this->serializer->serialize(span(kind: SpanKind::Internal)->end()))
        ->not->toHaveKey('namespace');
});

it('maps status onto the three failure booleans', function (SpanStatus $status, string $key): void {
    $document = $this->serializer->serialize(span()->setStatus($status)->end());

    expect($document[$key])->toBeTrue();
})->with([
    [SpanStatus::Error, 'error'],
    [SpanStatus::Fault, 'fault'],
    [SpanStatus::Throttle, 'throttle'],
]);

it('omits failure flags when the span succeeded', function (): void {
    $document = $this->serializer->serialize(span()->setStatus(SpanStatus::Ok)->end());

    expect($document)->not->toHaveKeys(['error', 'fault', 'throttle']);
});

it('records exceptions as a cause', function (): void {
    $document = $this->serializer->serialize(
        span()->recordException(new RuntimeException('it broke'))->end(),
    );

    expect($document['fault'])->toBeTrue()
        ->and($document['cause']['exceptions'][0])->toMatchArray([
            'type' => RuntimeException::class,
            'message' => 'it broke',
        ]);
});

it('promotes only configured attributes to indexed annotations', function (): void {
    $serializer = new XraySegmentSerializer('my-service', annotationKeys: ['http.route']);

    $document = $serializer->serialize(
        span()->setAttributes(['http.route' => 'users/{id}', 'other' => 'x'])->end(),
    );

    // Annotation keys must be alphanumeric plus underscore or X-Ray drops them.
    expect($document['annotations'])->toBe(['http_route' => 'users/{id}']);
});

it('keeps unconsumed attributes as metadata', function (): void {
    $document = $this->serializer->serialize(
        span()->setAttributes(['custom.field' => 'value', 'http.request.method' => 'GET'])->end(),
    );

    expect($document['metadata']['default'])->toHaveKey('custom.field')
        // Already folded into the http block.
        ->and($document['metadata']['default'])->not->toHaveKey('http.request.method');
});

it('truncates names to the documented 200 character limit', function (): void {
    $document = $this->serializer->serialize(span(name: str_repeat('a', 300))->end());

    expect(mb_strlen($document['name']))->toBe(200);
});

it('serializes to valid json', function (): void {
    $json = json_encode($this->serializer->serialize(span()->end()), JSON_THROW_ON_ERROR);

    expect($json)->toBeString();
});

it('emits a segment rather than an unattachable subsegment when there is no parent', function (): void {
    // Regression: under Lambda `tracing: Active` with no usable `Parent` in the
    // inbound header, spans were serialized as `type: subsegment` with no
    // `parent_id`. The daemon has nothing to attach those to and discards them,
    // silently losing every span in the request.
    $ids = new IdGenerator;

    $span = new Span(
        name: 'sqs.SendMessage',
        traceId: $ids->traceId(),
        spanId: $ids->spanId(),
        parentSpanId: null,
        kind: SpanKind::Client,
        startTime: microtime(true),
        isRoot: false,
    );

    $document = (new XraySegmentSerializer('svc'))->serialize($span->end());

    expect($document)->not->toHaveKey('type')
        ->and($document)->toHaveKey('service');
});

it('still emits a subsegment when a parent is known', function (): void {
    $ids = new IdGenerator;

    $span = new Span(
        name: 'sqs.SendMessage',
        traceId: $ids->traceId(),
        spanId: $ids->spanId(),
        parentSpanId: $ids->spanId(),
        kind: SpanKind::Client,
        startTime: microtime(true),
        isRoot: false,
    );

    $document = (new XraySegmentSerializer('svc'))->serialize($span->end());

    expect($document['type'])->toBe('subsegment')
        ->and($document)->toHaveKey('parent_id');
});
