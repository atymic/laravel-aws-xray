<?php

declare(strict_types=1);

namespace Atymic\Xray\Serializer;

use Atymic\Xray\Trace\Span;
use Atymic\Xray\Trace\SpanKind;
use Atymic\Xray\Trace\SpanStatus;

/**
 * Renders a {@see Span} as an X-Ray segment document.
 *
 * Written against the published segment-document spec rather than derived from
 * any existing implementation, both for correctness and to avoid inheriting the
 * BSD-3 obligations that attach to the established PHP X-Ray libraries.
 *
 * @see https://docs.aws.amazon.com/xray/latest/devguide/xray-api-segmentdocuments.html
 */
final readonly class XraySegmentSerializer
{
    public function __construct(
        private string $serviceName,
        private AttributeMapper $mapper = new AttributeMapper,
        /** @var list<string> Attributes promoted to indexed annotations. */
        private array $annotationKeys = [],
        private ?string $serviceVersion = null,
        private ?string $origin = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function serialize(Span $span): array
    {
        $attributes = $span->attributes();

        $document = [
            'id' => $span->spanId,
            'trace_id' => $span->traceId,
            'name' => $this->segmentName($span),
            'start_time' => $span->startTime,
        ];

        if ($span->isEnded()) {
            $document['end_time'] = $span->endTime();
        } else {
            $document['in_progress'] = true;
        }

        if ($span->parentSpanId !== null) {
            $document['parent_id'] = $span->parentSpanId;
        }

        // A subsegment sent on its own — rather than nested inside a parent's
        // `subsegments` array — must declare itself. This is the normal case
        // under Lambda `tracing: Active`, where the function segment belongs to
        // the runtime and cannot be modified.
        //
        // It must also name a parent: the daemon has nothing to attach a
        // parentless subsegment to and discards it. That happens under Active
        // tracing whenever the inbound header carried no usable `Parent`, which
        // would silently drop every span in the request. Emitting a segment
        // instead keeps the trace, at the cost of a second root alongside
        // Lambda's own.
        $isSegment = $span->isRoot || $span->parentSpanId === null;

        if (! $isSegment) {
            $document['type'] = 'subsegment';
        }

        if ($isSegment) {
            $document['service'] = array_filter([
                'version' => $this->serviceVersion,
            ], static fn ($v) => $v !== null);

            if ($this->origin !== null) {
                $document['origin'] = $this->origin;
            }

            if (isset($attributes['enduser.id'])) {
                $document['user'] = (string) $attributes['enduser.id'];
            }
        }

        $isAws = isset($attributes['rpc.system']) && $attributes['rpc.system'] === 'aws-api';

        if (($namespace = $span->kind->toXrayNamespace($isAws)) !== null) {
            $document['namespace'] = $namespace;
        }

        if (($http = $this->mapper->http($attributes)) !== []) {
            $document['http'] = $http;
        }

        if (isset($attributes['db.system.name']) && ($sql = $this->mapper->sql($attributes)) !== []) {
            $document['sql'] = $sql;
        }

        if (($aws = $this->mapper->aws($attributes)) !== []) {
            $document['aws'] = $aws;
        }

        if (($annotations = $this->mapper->annotations($attributes, $this->annotationKeys)) !== []) {
            $document['annotations'] = $annotations;
        }

        $metadata = $this->mapper->metadata($attributes);

        if ($span->events() !== []) {
            $metadata['events'] = $span->events();
        }

        if ($metadata !== []) {
            $document['metadata'] = ['default' => $metadata];
        }

        return $document + $this->errorFields($span);
    }

    /**
     * X-Ray's three failure booleans. Only emitted when true — a `false` here
     * is noise, and X-Ray defaults them to false anyway.
     *
     * @return array<string, mixed>
     */
    private function errorFields(Span $span): array
    {
        $fields = match ($span->status()) {
            SpanStatus::Error => ['error' => true],
            SpanStatus::Fault => ['fault' => true],
            SpanStatus::Throttle => ['throttle' => true, 'error' => true],
            SpanStatus::Ok, SpanStatus::Unset => [],
        };

        if ($span->exceptions() === []) {
            return $fields;
        }

        $exceptions = [];

        foreach ($span->exceptions() as $exception) {
            $exceptions[] = [
                'id' => bin2hex(random_bytes(8)),
                'type' => $exception['type'],
                'message' => $exception['message'],
                'stack' => $exception['stack'],
            ];
        }

        $fields['cause'] = ['exceptions' => $exceptions];

        return $fields;
    }

    /**
     * Segment names are limited to 200 characters and a restricted character
     * set. Root segments carry the service name so the service map has a stable
     * node; subsegments carry the operation.
     */
    private function segmentName(Span $span): string
    {
        $name = $span->isRoot && $span->kind === SpanKind::Server
            ? $this->serviceName
            : $span->name;

        $name = preg_replace('/[^\p{L}\p{N}\s_.:\/%&#=+\\\\\-@]/u', '', $name) ?? $name;

        return mb_substr($name, 0, 200);
    }
}
