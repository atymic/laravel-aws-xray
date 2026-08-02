<?php

declare(strict_types=1);

namespace Atymic\Xray\Serializer;

use Atymic\Xray\Trace\IdGenerator;
use Atymic\Xray\Trace\Span;

/**
 * Renders spans as an OTLP `ExportTraceServiceRequest`.
 *
 * Emits OTLP/JSON rather than protobuf. Both are accepted by the X-Ray OTLP
 * endpoint and by every OTLP collector, and JSON avoids taking a hard
 * dependency on a protobuf runtime for a payload this simple. Trace volume is
 * bounded by sampling, so the size difference is not on the critical path.
 *
 * @see https://opentelemetry.io/docs/specs/otlp/
 * @see https://docs.aws.amazon.com/AmazonCloudWatch/latest/monitoring/CloudWatch-OTLPEndpoint.html
 */
final readonly class OtlpSerializer
{
    public function __construct(
        private string $serviceName,
        private IdGenerator $ids = new IdGenerator,
        /** @var array<string, scalar> Resource-level attributes (function name, version, …). */
        private array $resourceAttributes = [],
        private string $scopeName = 'atymic/laravel-aws-xray',
        private string $scopeVersion = '1.0.0',
    ) {}

    /**
     * @param  list<Span>  $spans
     * @return array<string, mixed>
     */
    public function serialize(array $spans): array
    {
        return [
            'resourceSpans' => [
                [
                    'resource' => [
                        'attributes' => $this->attributes([
                            'service.name' => $this->serviceName,
                            ...$this->resourceAttributes,
                        ]),
                    ],
                    'scopeSpans' => [
                        [
                            'scope' => [
                                'name' => $this->scopeName,
                                'version' => $this->scopeVersion,
                            ],
                            'spans' => array_values(array_filter(
                                array_map($this->span(...), $spans),
                            )),
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function span(Span $span): ?array
    {
        $traceId = $this->ids->toW3C($span->traceId);

        // Without a well-formed trace id the span cannot be correlated, so
        // dropping it is better than shipping an orphan.
        if ($traceId === null) {
            return null;
        }

        $document = [
            'traceId' => $traceId,
            'spanId' => $span->spanId,
            'name' => $span->name,
            'kind' => $span->kind->toOtlp(),
            'startTimeUnixNano' => $this->nanos($span->startTime),
            'endTimeUnixNano' => $this->nanos($span->endTime() ?? microtime(true)),
            'attributes' => $this->attributes($span->attributes()),
        ];

        if ($span->parentSpanId !== null) {
            $document['parentSpanId'] = $span->parentSpanId;
        }

        $status = ['code' => $span->status()->toOtlp()];

        // OTLP has one error state where X-Ray has three; keep the distinction
        // discoverable by naming it rather than losing it.
        if ($span->status()->isFailure()) {
            $status['message'] = $span->status()->value;
        }

        $document['status'] = $status;

        $events = [];

        foreach ($span->events() as $event) {
            $events[] = [
                'name' => $event['name'],
                'timeUnixNano' => $this->nanos($event['time']),
                'attributes' => $this->attributes($event['attributes']),
            ];
        }

        foreach ($span->exceptions() as $exception) {
            $events[] = [
                'name' => 'exception',
                'timeUnixNano' => $this->nanos($span->endTime() ?? microtime(true)),
                'attributes' => $this->attributes([
                    'exception.type' => $exception['type'],
                    'exception.message' => $exception['message'],
                    'exception.stacktrace' => $this->stack($exception['stack']),
                ]),
            ];
        }

        if ($events !== []) {
            $document['events'] = $events;
        }

        return $document;
    }

    /**
     * OTLP/JSON encodes 64-bit values as strings, since JSON numbers cannot
     * carry them losslessly.
     */
    private function nanos(float $seconds): string
    {
        return sprintf('%d', (int) round($seconds * 1_000_000_000));
    }

    /**
     * @param  list<array{path: string, line: int, label: string}>  $frames
     */
    private function stack(array $frames): string
    {
        return implode("\n", array_map(
            static fn (array $f): string => sprintf('%s(%d): %s', $f['path'], $f['line'], $f['label']),
            $frames,
        ));
    }

    /**
     * @param  array<string, scalar|null>  $attributes
     * @return list<array{key: string, value: array<string, mixed>}>
     */
    private function attributes(array $attributes): array
    {
        $result = [];

        foreach ($attributes as $key => $value) {
            if ($value === null) {
                continue;
            }

            $result[] = [
                'key' => $key,
                'value' => match (true) {
                    is_bool($value) => ['boolValue' => $value],
                    is_int($value) => ['intValue' => (string) $value],
                    is_float($value) => ['doubleValue' => $value],
                    default => ['stringValue' => (string) $value],
                },
            ];
        }

        return $result;
    }
}
