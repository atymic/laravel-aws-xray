<?php

declare(strict_types=1);

namespace Atymic\Xray\Http\Middleware;

use Atymic\Xray\Lambda\ColdStart;
use Atymic\Xray\Lambda\Environment;
use Atymic\Xray\Octane\RequestScope;
use Atymic\Xray\Trace\Span;
use Atymic\Xray\Trace\SpanKind;
use Atymic\Xray\Trace\SpanStatus;
use Atymic\Xray\Trace\TraceHeader;
use Atymic\Xray\Tracer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Opens the root span for an HTTP request.
 *
 * Trace context is read from the request rather than a superglobal, because
 * under Octane `$_SERVER` is not reliably repopulated per request while the
 * `Request` object always is.
 */
final class TraceRequest
{
    public function __construct(
        private readonly Tracer $tracer,
        /** @var list<string> Paths never traced, e.g. health checks. */
        private readonly array $except = [],
        /** @var list<string> Request headers copied onto the span. */
        private readonly array $captureHeaders = [],
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldSkip($request)) {
            return $next($request);
        }

        $this->tracer->startTrace($this->inboundHeader($request), $request->path());

        $span = $this->tracer->startSpan(
            name: $request->method().' '.$this->routeName($request),
            kind: SpanKind::Server,
            attributes: $this->requestAttributes($request),
        );

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            // Rename here too: a request that throws still matched a route, and
            // failing requests are the ones most worth grouping by route.
            $this->renameToRoute($span, $request);
            $span?->recordException($e);
            $this->tracer->endSpan($span);

            throw $e;
        }

        $this->renameToRoute($span, $request);
        $this->recordResponse($span, $request, $response);
        $this->tracer->endSpan($span);

        return $response;
    }

    /**
     * Rename the span once the route is known.
     *
     * The middleware is prepended so the span covers as much of the request as
     * possible, which means it opens before the router has matched anything.
     * At that point only the raw path is available, and naming the span after
     * it would put every distinct URL — every user id — on the service map as
     * its own node. Renaming on the way out gives both wide coverage and a
     * low-cardinality name.
     */
    private function renameToRoute(?Span $span, Request $request): void
    {
        $route = $request->route();

        if ($span === null || $route === null || ! method_exists($route, 'uri')) {
            return;
        }

        $span->rename($request->method().' /'.ltrim($route->uri(), '/'));
    }

    /**
     * Close the trace and emit.
     *
     * Under Octane this is a no-op — `RequestTerminated` owns the lifecycle
     * there — but on a traditional FPM deployment `terminate()` is the last
     * point at which we run, and it happens after the response is flushed.
     *
     * @see RequestScope
     */
    public function terminate(Request $request, Response $response): void
    {
        if (! RequestScope::isOctane()) {
            $this->tracer->endTrace();
        }
    }

    private function inboundHeader(Request $request): TraceHeader
    {
        $header = $request->header('X-Amzn-Trace-Id');

        if (is_string($header) && $header !== '') {
            return TraceHeader::parse($header);
        }

        // No inbound header: on Lambda the runtime still exposes the
        // invocation's own context, which keeps us attached to the trace API
        // Gateway started.
        return Environment::isLambda() ? Environment::traceHeader() : new TraceHeader;
    }

    /**
     * @return array<string, mixed>
     */
    private function requestAttributes(Request $request): array
    {
        $attributes = [
            'http.request.method' => $request->method(),
            'url.full' => $request->fullUrl(),
            'url.path' => '/'.ltrim($request->path(), '/'),
            'url.scheme' => $request->getScheme(),
            'server.address' => $request->getHost(),
            'server.port' => $request->getPort(),
            'client.address' => $request->ip(),
            'user_agent.original' => $request->userAgent(),
            'network.protocol.version' => $request->getProtocolVersion(),
        ];

        if (($route = $request->route()) !== null && method_exists($route, 'uri')) {
            $attributes['http.route'] = $route->uri();
        }

        foreach ($this->captureHeaders as $header) {
            if (($value = $request->header($header)) !== null) {
                $attributes['http.request.header.'.strtolower($header)] = is_array($value)
                    ? implode(', ', $value)
                    : $value;
            }
        }

        // Only the first request in an execution environment pays the cold
        // start, so attribute it to exactly that one.
        $attributes += ColdStart::claim();

        return $attributes;
    }

    private function recordResponse(?Span $span, Request $request, Response $response): void
    {
        if ($span === null) {
            return;
        }

        $span->setAttribute('http.response.status_code', $response->getStatusCode());

        if (($length = $response->headers->get('Content-Length')) !== null) {
            $span->setAttribute('http.response.body.size', (int) $length);
        }

        // Resolve the route late: the matched route is unknown until the
        // request has been through the router.
        if (($route = $request->route()) !== null && method_exists($route, 'uri')) {
            $span->setAttribute('http.route', $route->uri());
        }

        if (($user = $request->user()) !== null && method_exists($user, 'getAuthIdentifier')) {
            $span->setAttribute('enduser.id', (string) $user->getAuthIdentifier());
        }

        $span->setStatus(SpanStatus::fromHttpStatus($response->getStatusCode()));
    }

    private function shouldSkip(Request $request): bool
    {
        $path = '/'.ltrim($request->path(), '/');

        foreach ($this->except as $pattern) {
            if (Str::is($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    private function routeName(Request $request): string
    {
        $route = $request->route();

        if ($route !== null && method_exists($route, 'uri')) {
            return '/'.ltrim($route->uri(), '/');
        }

        return '/'.ltrim($request->path(), '/');
    }
}
