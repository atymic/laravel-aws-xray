<?php

declare(strict_types=1);

namespace Atymic\Xray\Serializer;

/**
 * Translates OpenTelemetry semantic-convention attributes into the structured
 * sub-objects an X-Ray segment document expects.
 *
 * Instrumentation sets attributes once, in semconv naming. The OTLP serializer
 * passes them through untouched; this class is what the X-Ray serializer uses
 * to build the `http`, `sql` and `aws` blocks. Keeping the translation here —
 * as a pure function over an attribute bag — means it is testable without AWS,
 * a socket, or a Laravel application.
 *
 * @see https://opentelemetry.io/docs/specs/semconv/
 * @see https://docs.aws.amazon.com/xray/latest/devguide/xray-api-segmentdocuments.html
 */
final class AttributeMapper
{
    /**
     * Attributes consumed into X-Ray's structured blocks. Anything not listed
     * here falls through to `metadata`, so custom attributes are never lost.
     *
     * @var list<string>
     */
    private const CONSUMED = [
        'http.request.method',
        'http.response.status_code',
        'http.response.body.size',
        'url.full',
        'url.path',
        'url.scheme',
        'user_agent.original',
        'client.address',
        'server.address',
        'server.port',
        'db.system.name',
        'db.namespace',
        'db.query.text',
        'db.operation.name',
        'rpc.service',
        'rpc.method',
        'aws.region',
        'aws.request_id',
        'aws.queue_url',
        'aws.table_name',
        'cloud.account.id',
        'enduser.id',
    ];

    /**
     * @param  array<string, scalar|null>  $attributes
     * @return array{request?: array<string, scalar>, response?: array<string, scalar>}
     */
    public function http(array $attributes): array
    {
        $request = array_filter([
            'method' => $attributes['http.request.method'] ?? null,
            'url' => $attributes['url.full'] ?? null,
            'user_agent' => $attributes['user_agent.original'] ?? null,
            'client_ip' => $attributes['client.address'] ?? null,
        ], static fn ($v) => $v !== null);

        $response = array_filter([
            'status' => isset($attributes['http.response.status_code'])
                ? (int) $attributes['http.response.status_code']
                : null,
            'content_length' => isset($attributes['http.response.body.size'])
                ? (int) $attributes['http.response.body.size']
                : null,
        ], static fn ($v) => $v !== null);

        return array_filter([
            'request' => $request === [] ? null : $request,
            'response' => $response === [] ? null : $response,
        ], static fn ($v) => $v !== null);
    }

    /**
     * @param  array<string, scalar|null>  $attributes
     * @return array<string, scalar>
     */
    public function sql(array $attributes): array
    {
        return array_filter([
            'database_type' => $attributes['db.system.name'] ?? null,
            // X-Ray names this `url`, but it is the connection target rather
            // than an HTTP URL.
            'url' => $attributes['db.namespace'] ?? null,
            'sanitized_query' => $attributes['db.query.text'] ?? null,
            'user' => $attributes['db.user'] ?? null,
            // Documented as `call` for a PreparedCall and `statement` for a
            // PreparedStatement — it describes how the query was prepared, not
            // which operation it ran. Laravel goes through PDO prepared
            // statements, so this is constant. The operation name is left in
            // metadata rather than being forced in here.
            'preparation' => isset($attributes['db.query.text']) ? 'statement' : null,
        ], static fn ($v) => $v !== null);
    }

    /**
     * @param  array<string, scalar|null>  $attributes
     * @return array<string, scalar>
     */
    public function aws(array $attributes): array
    {
        return array_filter([
            'operation' => $attributes['rpc.method'] ?? null,
            'region' => $attributes['aws.region'] ?? null,
            'request_id' => $attributes['aws.request_id'] ?? null,
            'queue_url' => $attributes['aws.queue_url'] ?? null,
            'table_name' => $attributes['aws.table_name'] ?? null,
            'account_id' => $attributes['cloud.account.id'] ?? null,
            // The documented subsegment `aws` fields are exactly operation,
            // account_id, region, request_id, queue_url and table_name. Bucket,
            // key and the rest have no documented home here, so they fall
            // through to metadata rather than being invented.
        ], static fn ($v) => $v !== null);
    }

    /**
     * Attributes X-Ray indexes and can filter on.
     *
     * Keys must be alphanumeric plus underscore — X-Ray silently drops the
     * rest — so dots become underscores. X-Ray indexes at most 50 annotations
     * per trace.
     *
     * @param  array<string, scalar|null>  $attributes
     * @param  list<string>  $keys
     * @return array<string, scalar>
     */
    public function annotations(array $attributes, array $keys): array
    {
        $annotations = [];

        foreach ($keys as $key) {
            $value = $attributes[$key] ?? null;

            if ($value === null) {
                continue;
            }

            $annotations[preg_replace('/[^a-zA-Z0-9_]/', '_', $key)] = $value;
        }

        return $annotations;
    }

    /**
     * Everything not folded into a structured block, so custom instrumentation
     * attributes survive the round trip.
     *
     * @param  array<string, scalar|null>  $attributes
     * @return array<string, scalar|null>
     */
    public function metadata(array $attributes): array
    {
        return array_diff_key($attributes, array_flip(self::CONSUMED));
    }
}
