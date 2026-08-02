<?php

declare(strict_types=1);

use Atymic\Xray\Trace\Span;
use Atymic\Xray\Trace\SpanKind;
use Atymic\Xray\Trace\SpanStack;

function makeSpan(string $name): Span
{
    return new Span(
        name: $name,
        traceId: '1-5759e988-bd862e3fe1be46a994272793',
        spanId: substr(md5($name), 0, 16),
        parentSpanId: null,
        kind: SpanKind::Internal,
        startTime: microtime(true),
    );
}

it('tracks the innermost open span', function (): void {
    $stack = new SpanStack;

    expect($stack->current())->toBeNull();

    $stack->push($outer = makeSpan('outer'));
    expect($stack->current())->toBe($outer);

    $stack->push($inner = makeSpan('inner'));
    expect($stack->current())->toBe($inner);

    $stack->pop();
    expect($stack->current())->toBe($outer);
});

it('keeps same-named siblings distinct', function (): void {
    // Keying open spans by name — as the established prior art does — makes
    // two concurrent spans of the same name clobber each other.
    $stack = new SpanStack;

    $stack->push($first = makeSpan('query'));
    $stack->push($second = makeSpan('query'));

    expect($stack->depth())->toBe(2)
        ->and($stack->pop())->toBe($second)
        ->and($stack->pop())->toBe($first);
});

it('closes inner spans when an outer one is popped', function (): void {
    // An exception unwinding past several open spans must not leave them
    // wedged on the stack, where they would attach to the next request.
    $stack = new SpanStack;

    $stack->push($outer = makeSpan('outer'));
    $stack->push($middle = makeSpan('middle'));
    $stack->push($inner = makeSpan('inner'));

    $stack->pop($outer);

    expect($stack->isEmpty())->toBeTrue()
        ->and($middle->isEnded())->toBeTrue()
        ->and($inner->isEnded())->toBeTrue();
});

it('ignores a pop for a span it does not hold', function (): void {
    $stack = new SpanStack;
    $stack->push(makeSpan('held'));

    expect($stack->pop(makeSpan('foreign')))->toBeNull()
        ->and($stack->depth())->toBe(1);
});

it('pops an empty stack safely', function (): void {
    expect((new SpanStack)->pop())->toBeNull();
});

it('flushes every open span innermost first', function (): void {
    $stack = new SpanStack;
    $stack->push(makeSpan('a'));
    $stack->push(makeSpan('b'));
    $stack->push(makeSpan('c'));

    $flushed = $stack->flush();

    expect($flushed)->toHaveCount(3)
        ->and($flushed[0]->name)->toBe('c')
        ->and($stack->isEmpty())->toBeTrue()
        ->and(array_filter($flushed, fn (Span $s) => ! $s->isEnded()))->toBeEmpty();
});
