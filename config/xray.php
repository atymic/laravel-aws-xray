<?php

declare(strict_types=1);

use Atymic\Xray\Instrumentation;

return [

    'enabled' => env('XRAY_ENABLED', true),

    /*
    |---------------------------------------------------------------------------
    | Emitter
    |---------------------------------------------------------------------------
    |
    | Where finished spans go. All three AWS options terminate at CloudWatch
    | Transaction Search — X-Ray segments are converted to OpenTelemetry format
    | on ingest — so this is a transport choice, not a product choice.
    |
    |   auto       Pick the best available (see the resolution order below).
    |   xray       UDP to the X-Ray daemon. Costs microseconds and cannot delay
    |              a response, but needs `tracing: Active`, which means Lambda
    |              owns the sampling decision and fixes it at roughly 5%.
    |   collector  OTLP to a collector running beside the app (Rotel as a Lambda
    |              extension). Loopback write, forwarded off the request path.
    |              Keeps sampling ours. The best of both, at the cost of an
    |              extension in the image.
    |   otlp       OTLP straight to the X-Ray endpoint, SigV4-signed. Needs
    |              nothing alongside it, so it works outside Lambda — but it is
    |              the one emitter that must finish before the response returns,
    |              because Lambda freezes the environment at that point.
    |   log        Write segments to the log. For local development.
    |   null       Discard. The default while testing.
    |
    | `auto` resolves: collector (if an OTLP endpoint is configured) → xray (if
    | a daemon address is present) → log locally, otherwise null.
    |
    | `otlp` is never auto-selected. It is the only transport that blocks the
    | response, and its availability check comes down to having credentials —
    | which Lambda always injects — so elimination would land on it for every
    | function without a collector or daemon. Ask for it by name.
    |
    */

    'emitter' => env('XRAY_EMITTER', 'auto'),

    'daemon' => [
        // Lambda injects this when tracing is Active; the default matters only
        // when running a daemon yourself.
        'address' => env('AWS_XRAY_DAEMON_ADDRESS', '127.0.0.1:2000'),
    ],

    'collector' => [
        'endpoint' => env('OTEL_EXPORTER_OTLP_TRACES_ENDPOINT', env('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://127.0.0.1:4318')),
        'timeout' => (float) env('XRAY_COLLECTOR_TIMEOUT', 1.0),
    ],

    'otlp' => [
        'region' => env('AWS_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
        // Defaults to https://xray.{region}.amazonaws.com/v1/traces
        'endpoint' => env('XRAY_OTLP_ENDPOINT'),
        'timeout' => (float) env('XRAY_OTLP_TIMEOUT', 3.0),
    ],

    /*
    |---------------------------------------------------------------------------
    | Service identity
    |---------------------------------------------------------------------------
    |
    | Names the node on the X-Ray service map. Keep it stable — changing it
    | splits one service into two on the map.
    |
    */

    'service' => [
        'name' => env('XRAY_SERVICE_NAME', env('APP_NAME', 'laravel')),
        'version' => env('XRAY_SERVICE_VERSION'),
    ],

    /*
    |---------------------------------------------------------------------------
    | Sampling
    |---------------------------------------------------------------------------
    |
    | Only consulted by the collector and otlp emitters. Under `xray` the
    | decision has already been made by Lambda and cannot be changed.
    |
    | An inbound `Sampled` in the trace header always wins, so upstream
    | decisions propagate and traces do not lose their middle.
    |
    | Note that tail latency is, by definition, rare: at 5% sampling most slow
    | requests are never recorded. Sample high if the point is chasing p99.
    |
    */

    'sampling' => [
        'rate' => (float) env('XRAY_SAMPLING_RATE', 1.0),

        // First match wins.
        'rules' => [
            ['path' => '/health*', 'rate' => 0.0],
            ['path' => '/up', 'rate' => 0.0],
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | HTTP
    |---------------------------------------------------------------------------
    */

    'http' => [
        // Never traced at all — cheaper than sampling them out.
        'except' => [
            '/health*',
            '/up',
            '/horizon*',
            '/telescope*',
        ],

        // Copied onto the root span. Avoid anything carrying credentials.
        'capture_headers' => [
            'referer',
            'x-request-id',
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Annotations
    |---------------------------------------------------------------------------
    |
    | Attributes promoted to indexed X-Ray annotations, which is what makes them
    | filterable. X-Ray indexes at most 50 per trace, so keep this short and
    | low-cardinality.
    |
    */

    'annotations' => [
        'http.route',
        'http.response.status_code',
        'faas.coldstart',
    ],

    /*
    |---------------------------------------------------------------------------
    | Instrumentation
    |---------------------------------------------------------------------------
    |
    | Remove an entry to stop collecting it. Keys are class names implementing
    | Atymic\Xray\Instrumentation\Instrumentation, so your own can be added here
    | without touching the package.
    |
    */

    'instrumentation' => [

        Instrumentation\DatabaseInstrumentation::class => [
            'enabled' => true,
            'max_query_length' => 500,
            // Bindings frequently contain personal data, and spans are billed
            // by the byte.
            'include_bindings' => false,
            'transactions' => true,
        ],

        Instrumentation\HttpClientInstrumentation::class => [
            'enabled' => true,
            // Add the trace header to outgoing requests, so downstream services
            // join this trace.
            'propagate' => true,
        ],

        Instrumentation\QueueInstrumentation::class => [
            'enabled' => true,
        ],

        // Requires aws/aws-sdk-php. Any client resolved through the container
        // is instrumented automatically; one built with `new S3Client(...)`
        // must be passed to AwsSdkInstrumentation::instrument() by hand.
        Instrumentation\AwsSdkInstrumentation::class => [
            'enabled' => true,
        ],

        Instrumentation\ExceptionInstrumentation::class => [
            'enabled' => true,
            'stack_limit' => 10,
        ],

        Instrumentation\CacheInstrumentation::class => [
            'enabled' => true,
        ],

        Instrumentation\RedisInstrumentation::class => [
            'enabled' => false,
            'max_command_length' => 200,
        ],

        Instrumentation\ViewInstrumentation::class => [
            'enabled' => false,
        ],

        Instrumentation\ConsoleInstrumentation::class => [
            'enabled' => true,
            // Opt-in: scheduled commands run constantly and would swamp the
            // traces that matter. Wildcards allowed.
            'commands' => [],
        ],

    ],

];
