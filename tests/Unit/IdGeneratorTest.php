<?php

declare(strict_types=1);

use Atymic\Xray\Trace\IdGenerator;

beforeEach(function (): void {
    $this->ids = new IdGenerator;
});

it('generates trace ids in X-Ray format', function (): void {
    expect($this->ids->traceId())->toMatch('/^1-[0-9a-f]{8}-[0-9a-f]{24}$/');
});

it('encodes the current time in the trace id', function (): void {
    $traceId = $this->ids->traceId(1480615200);

    expect($traceId)->toStartWith('1-58406520-');
});

it('generates 16 hex digit span ids', function (): void {
    expect($this->ids->spanId())->toMatch('/^[0-9a-f]{16}$/');
});

it('generates distinct ids', function (): void {
    $ids = array_map(fn () => $this->ids->spanId(), range(1, 100));

    expect(array_unique($ids))->toHaveCount(100);
});

it('flattens an X-Ray trace id to W3C form', function (): void {
    // The two formats describe the same 128 bits; the hyphens are presentation.
    expect($this->ids->toW3C('1-5759e988-bd862e3fe1be46a994272793'))
        ->toBe('5759e988bd862e3fe1be46a994272793');
});

it('widens a W3C trace id back to X-Ray form', function (): void {
    expect($this->ids->fromW3C('5759e988bd862e3fe1be46a994272793'))
        ->toBe('1-5759e988-bd862e3fe1be46a994272793');
});

it('round-trips between formats', function (): void {
    $original = $this->ids->traceId();

    expect($this->ids->fromW3C($this->ids->toW3C($original)))->toBe($original);
});

it('rejects malformed ids rather than emitting an uncorrelatable span', function (): void {
    expect($this->ids->toW3C('nonsense'))->toBeNull()
        ->and($this->ids->fromW3C('too-short'))->toBeNull();
});

it('validates id formats', function (): void {
    expect(IdGenerator::isValidTraceId('1-5759e988-bd862e3fe1be46a994272793'))->toBeTrue()
        ->and(IdGenerator::isValidTraceId('2-5759e988-bd862e3fe1be46a994272793'))->toBeFalse()
        ->and(IdGenerator::isValidTraceId('1-5759E988-bd862e3fe1be46a994272793'))->toBeFalse()
        ->and(IdGenerator::isValidSpanId('53995c3f42cd8ad8'))->toBeTrue()
        ->and(IdGenerator::isValidSpanId('53995c3f'))->toBeFalse();
});
