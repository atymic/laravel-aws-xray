<?php

declare(strict_types=1);

use Atymic\Xray\Trace\TraceHeader;

it('parses a full header', function (): void {
    $header = TraceHeader::parse(
        'Root=1-5759e988-bd862e3fe1be46a994272793;Parent=53995c3f42cd8ad8;Sampled=1',
    );

    expect($header->traceId)->toBe('1-5759e988-bd862e3fe1be46a994272793')
        ->and($header->parentId)->toBe('53995c3f42cd8ad8')
        ->and($header->sampled)->toBeTrue();
});

it('treats Sampled=0 as an explicit no', function (): void {
    // Naive truthiness on the string "0" is a classic bug here: it makes an
    // upstream opt-out sample anyway.
    expect(TraceHeader::parse('Root=1-5759e988-bd862e3fe1be46a994272793;Sampled=0')->sampled)
        ->toBeFalse();
});

it('treats Sampled=? as undecided so the sampler chooses', function (): void {
    expect(TraceHeader::parse('Root=1-5759e988-bd862e3fe1be46a994272793;Sampled=?')->sampled)
        ->toBeNull();
});

it('ignores the Lineage field', function (): void {
    $header = TraceHeader::parse(
        'Root=1-5759e988-bd862e3fe1be46a994272793;Sampled=1;Lineage=25:a87bd80c:1',
    );

    expect($header->traceId)->toBe('1-5759e988-bd862e3fe1be46a994272793')
        ->and((string) $header)->not->toContain('Lineage');
});

it('rejects a malformed root rather than propagating it', function (): void {
    // A bad trace id produces traces that silently never appear, which is
    // worse than starting a fresh one.
    expect(TraceHeader::parse('Root=not-a-trace-id;Sampled=1')->traceId)->toBeNull();
});

it('rejects a malformed parent', function (): void {
    expect(TraceHeader::parse('Root=1-5759e988-bd862e3fe1be46a994272793;Parent=xyz')->parentId)
        ->toBeNull();
});

it('handles absent and empty input', function (?string $input): void {
    $header = TraceHeader::parse($input);

    expect($header->hasTrace())->toBeFalse()
        ->and((string) $header)->toBe('');
})->with([null, '', '   ']);

it('round-trips through its string form', function (): void {
    $original = 'Root=1-5759e988-bd862e3fe1be46a994272793;Parent=53995c3f42cd8ad8;Sampled=1';

    expect((string) TraceHeader::parse($original))->toBe($original);
});

it('is case insensitive on field names', function (): void {
    expect(TraceHeader::parse('root=1-5759e988-bd862e3fe1be46a994272793')->traceId)
        ->toBe('1-5759e988-bd862e3fe1be46a994272793');
});
