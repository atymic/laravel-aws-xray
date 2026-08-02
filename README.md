# laravel-aws-xray

AWS X-Ray tracing for Laravel on Lambda — built for Octane, Bref, and CloudWatch
Transaction Search.

Distributed tracing across API Gateway → Lambda → DB → SQS → worker, with three
interchangeable transports so you can trade request latency against sampling
control rather than being stuck with whichever one the library picked.

## Why another one

Existing Laravel X-Ray packages predate Octane. They hold the tracer in a static
singleton and read the trace header from `$_SERVER` at boot — which works
perfectly on a cold container serving one request, and silently corrupts every
trace after that. Under `BREF_LOOP_MAX: 250` that is 249 broken requests out of
250.

This package scopes trace state to the request, resets it on Octane's request
lifecycle, and has a test that drives 250 requests through one tracer asserting
250 distinct trace ids.

## Installation

```bash
composer require atymic/laravel-aws-xray
php artisan vendor:publish --tag="aws-xray-config"
```

## Transports

All three land in the same place. X-Ray traces are converted to OpenTelemetry
semantic-convention format on ingest, so both wire formats end up in the
`aws/spans` log group, queried the same way through Transaction Search.

| | `xray` (UDP) | `collector` | `otlp` (direct) |
| --- | --- | --- | --- |
| Request-path cost | ~microseconds | ~sub-ms loopback | TLS + signing + RTT |
| Blocks the response | no | no | **yes** |
| Sampling control | Lambda's, **fixed** | **yours** | **yours** |
| Lambda tracing mode | **`Active`** | `PassThrough` | `PassThrough` |
| Needs anything alongside | daemon (automatic) | collector in the image | nothing |
| Works outside Lambda | no | yes | **yes** |

```php
'emitter' => env('XRAY_EMITTER', 'auto'),
```

`auto` resolves collector → daemon → direct, taking the first that can actually
deliver. In a test suite it always resolves to `null`, so an application's tests
never emit anything.

### `xray` — UDP to the daemon

Cheapest possible: `socket_sendto` returns as soon as the datagram hits the
kernel buffer. No acknowledgement, no retry, no possible effect on response time.

Requires `tracing: Active` in `serverless.yml`, which is what makes Lambda run a
daemon. The catch is that Active tracing also means **Lambda owns the sampling
decision** — 1 request/second plus 5% of the remainder, and
[not configurable](https://docs.aws.amazon.com/lambda/latest/dg/services-xray.html).
If the point is chasing p99 latency, that is the wrong 5%.

### `collector` — OTLP to a local collector

Post OTLP to `localhost:4318`; a collector running as a Lambda extension signs
and forwards it. Because an extension keeps running briefly after the handler
returns, the expensive part happens off the request path — so this gives 100%
sampling *and* a clean request path.

[Rotel](https://github.com/streamfold/rotel-lambda-extension) is the one to use:
an 8 MB Rust binary with a sub-70ms cold start and a native `awsxray` exporter.
The reference OTel collector is a ~100–210 MB Go binary with a reported ~4s cold
start, which is disqualifying on anything latency-sensitive.

Layer ARNs cannot be attached to container-image functions, so bake the binary
into `/opt/extensions/` in `Dockerfile.bref`.

### `otlp` — direct to the X-Ray endpoint

SigV4-signed POST to `https://xray.{region}.amazonaws.com/v1/traces`. Needs
nothing alongside it, so it is the one that works locally, on EC2, or in a
plain container.

It is also the only one that **must finish before the handler returns** — Lambda
freezes the execution environment at that point, so an in-flight request would
never complete. Measure it before making it the default on Lambda.

Requires CloudWatch Transaction Search to be enabled. Note that enabling it is
**account-and-region-wide**, not per-application.

## Usage

Instrumentation is automatic. To trace your own work:

```php
use Atymic\Xray\Facades\Xray;

$result = Xray::trace('generate-report', function () {
    return Report::generate();
});
```

Prefer `trace()` over manual start/end: it closes the span in a `finally`, so an
exception cannot leak a span into the next request.

Add detail to whatever is currently open:

```php
Xray::currentSpan()?->setAttribute('report.rows', $rows);
```

## What gets traced

| Instrumentation | Default | Notes |
| --- | --- | --- |
| HTTP requests | on | Root span, named by route pattern rather than URL |
| Database queries | on | Backdated from `QueryExecuted`; bindings off by default |
| HTTP client | on | Spans plus trace-header propagation |
| Queue jobs | on | Both dispatch and processing |
| AWS SDK | on | Needs `aws/aws-sdk-php`; downstream nodes on the service map |
| Cold start | on | HTTP, queue and console entry points |
| Exceptions | on | Attached to the innermost open span |
| Cache | on | Span events, not spans |
| Redis | off | |
| Views | off | |
| Artisan commands | on | Opt-in per command name |

### AWS SDK calls

Calls through the AWS SDK become `CLIENT` spans in the `aws` namespace, which is
what draws SQS, DynamoDB and S3 as their own nodes on the service map rather
than leaving unexplained gaps inside a request.

The SDK has no global middleware registry, so this hooks Laravel's container:
any client resolved through it is instrumented automatically. A client built
directly has to be handed over once:

```php
use Atymic\Xray\Instrumentation\AwsSdkInstrumentation;

$client = AwsSdkInstrumentation::instrument(new S3Client([...]));
```

Only the fields identifying the target are captured — queue URL, table name,
bucket and key. Message bodies and item attributes are never read: they carry
user data, and spans are billed by the byte.

### Cold starts

The first invocation in an execution environment is tagged `faas.coldstart`,
with `faas.boot_duration_ms` measured from `LARAVEL_START`. Claimed by whichever
entry point runs first — a queue worker or a scheduled command pays the cold
start just as a web request does, so all three record it.

### Distributed tracing through SQS

Trace context travels in the job payload, and on SQS is also read from the
`AWSTraceHeader` system attribute — the one AWS services populate themselves.
Context is resolved **per message**, because one Lambda invocation can deliver
up to ten messages from ten different producer traces.

## Sampling

```php
'sampling' => [
    'rate' => (float) env('XRAY_SAMPLING_RATE', 1.0),
    'rules' => [
        ['path' => '/health*', 'rate' => 0.0],
    ],
],
```

Ignored by the `xray` emitter — under Active tracing the decision is Lambda's.
An inbound `Sampled` in the trace header always wins, so upstream decisions
propagate and traces do not lose their middle.

## Payload limits

The X-Ray OTLP endpoint rejects the **entire request** when any of its limits is
exceeded — 5 MB uncompressed, 10,000 spans, 200 KB per span, and timestamps
within 2 hours ahead or 14 days back. One oversized span would otherwise discard
a whole request's trace, and the only sign would be a warning in the log.

Both OTLP emitters guard against this before sending:

- Oversized spans have their largest attribute values truncated, and are dropped
  only if still too large — which keeps the rest of the trace.
- Batches over the span-count or byte ceiling are **split**, never truncated.
- Spans outside the accepted time window are dropped individually, so one
  clock-skewed span cannot take the batch with it.

Anything dropped is logged at warning level with the span name and its size.

The collector path applies the same limits, since a local collector will accept
a payload AWS later rejects — at which point nothing is left to report it.

## Cost

Transaction Search bills per byte, so **span size matters more than sampling
rate**. Full SQL text, request bodies and stack traces turn a 1 KB span into
4 KB and quadruple the bill. The defaults truncate queries at 500 characters,
omit bindings, and cap stack traces at 10 frames.

## Testing

Set the emitter to `memory` and assert on what was produced:

```php
config()->set('xray.emitter', 'memory');

$spans = app(\Atymic\Xray\Emitter\MemoryEmitter::class)->spans();
```

## Octane

Works under Bref's `OctaneHandler`, which drives the stock
`Laravel\Octane\Worker`, so `RequestReceived` and `RequestTerminated` both fire.
The trace is flushed in `RequestTerminated` — after the response has already gone
to the client.

`WorkerStopping` is deliberately not used to flush: on Lambda a sandbox is
usually frozen and later reaped without a graceful shutdown, so anything relying
on it would be lost.

## License

MIT.
