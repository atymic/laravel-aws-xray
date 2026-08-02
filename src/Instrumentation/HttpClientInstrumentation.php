<?php

declare(strict_types=1);

namespace Atymic\Xray\Instrumentation;

use Atymic\Xray\Trace\Span;
use Atymic\Xray\Trace\SpanKind;
use Atymic\Xray\Trace\SpanStatus;
use Atymic\Xray\Tracer;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Factory;
use Psr\Http\Message\RequestInterface;

/**
 * Spans for outgoing HTTP calls made through Laravel's HTTP client.
 *
 * Also injects the trace header into every outgoing request, which is what
 * makes a call to another instrumented service continue this trace rather than
 * start its own.
 */
final class HttpClientInstrumentation implements Instrumentation
{
    public function register(Application $app, array $options = []): void
    {
        $events = $app['events'];
        $tracer = $app->make(Tracer::class);

        // Keyed by the request object so concurrent calls cannot be confused
        // for one another.
        $spans = new \SplObjectStorage;

        $events->listen(RequestSending::class, function (RequestSending $event) use ($tracer, $spans): void {
            if (! $tracer->isRecording()) {
                return;
            }

            $uri = $event->request->toPsrRequest()->getUri();

            $span = $tracer->startSpan(
                name: $event->request->method().' '.$uri->getHost(),
                kind: SpanKind::Client,
                attributes: [
                    'http.request.method' => $event->request->method(),
                    'url.full' => $this->redact((string) $uri),
                    'url.scheme' => $uri->getScheme(),
                    'server.address' => $uri->getHost(),
                    'server.port' => $uri->getPort(),
                ],
            );

            if ($span !== null) {
                $spans[$event->request] = $span;
            }
        });

        $events->listen(ResponseReceived::class, function (ResponseReceived $event) use ($tracer, $spans): void {
            if (! isset($spans[$event->request])) {
                return;
            }

            /** @var Span $span */
            $span = $spans[$event->request];
            unset($spans[$event->request]);

            $span->setAttribute('http.response.status_code', $event->response->status());
            $span->setStatus(SpanStatus::fromHttpStatus($event->response->status()));

            $tracer->endSpan($span);
        });

        $events->listen(ConnectionFailed::class, function (ConnectionFailed $event) use ($tracer, $spans): void {
            if (! isset($spans[$event->request])) {
                return;
            }

            /** @var Span $span */
            $span = $spans[$event->request];
            unset($spans[$event->request]);

            $span->setStatus(SpanStatus::Fault)
                ->setAttribute('error.type', 'connection_failed');

            $tracer->endSpan($span);
        });

        if ($options['propagate'] ?? true) {
            $this->registerPropagation($app, $tracer);
        }
    }

    /**
     * Add the trace header to outgoing requests via a global Guzzle middleware.
     */
    private function registerPropagation(Application $app, Tracer $tracer): void
    {
        $app->make(Factory::class)->globalMiddleware(
            static function (callable $handler) use ($tracer) {
                return static function (RequestInterface $request, array $options) use ($handler, $tracer) {
                    $header = $tracer->propagationHeader();

                    if ($header !== null && $header->hasTrace()) {
                        $request = $request->withHeader('X-Amzn-Trace-Id', (string) $header);
                    }

                    return $handler($request, $options);
                };
            },
        );
    }

    /**
     * Strip credentials and query strings — URLs routinely carry tokens, and
     * span attributes are stored and billed.
     */
    private function redact(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return $url;
        }

        return sprintf(
            '%s://%s%s%s',
            $parts['scheme'] ?? 'http',
            $parts['host'] ?? '',
            isset($parts['port']) ? ':'.$parts['port'] : '',
            $parts['path'] ?? '',
        );
    }
}
