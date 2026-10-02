<?php

declare(strict_types=1);

namespace RudolfBruder\LaravelSnip\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RudolfBruder\LaravelSnip\Http\Renderers\SnippetRenderer;
use RudolfBruder\LaravelSnip\Profiler;
use RudolfBruder\LaravelSnip\SnipManager;
use RudolfBruder\LaravelSnip\Support\PendingPayloadStore;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Global middleware that:
 *
 *   1. Arms the request's Profiler, when it is switched on.
 *   2. Hydrates the manager from any prior-request session flash.
 *   3. Injects the panel snippet into HTML responses (when capturing).
 *   4. Flashes the current request's captures into the session whenever
 *      the response is a plain redirect, so the next request can render
 *      them.
 *
 * Stays thin on purpose — every heavier concern (rendering, payload
 * assembly, asset URL, session storage) lives in a dedicated collaborator.
 */
class InjectSnip
{
    public function __construct(
        protected SnipManager $manager,
        protected ConfigRepository $config,
        protected SnippetRenderer $renderer,
        protected PendingPayloadStore $pendingStore,
        protected Profiler $profiler,
    ) {}

    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        $this->profiler->start();

        /** @var SymfonyResponse $response */
        $response = $next($request);

        // Hydrate AFTER $next so StartSession (inside the web group) has
        // already bound the session. Doing it before $next would always
        // see hasSession() === false because InjectSnip is registered on
        // the global middleware stack.
        $this->manager->ensureHydratedFromSession($request);

        if ($this->shouldInject($response)) {
            return $this->renderer->inject($response);
        }

        $this->flashPendingForNextRequest($request, $response);

        return $response;
    }

    protected function shouldInject(SymfonyResponse $response): bool
    {
        if (! $this->config->get('snip.enabled', true)) {
            return false;
        }

        if (! $response instanceof Response) {
            return false;
        }

        if (! $this->isHtmlResponse($response)) {
            return false;
        }

        $content = $response->getContent();

        if ($content === false || ! str_contains($content, '</body>')) {
            return false;
        }

        $mode = (string) $this->config->get('snip.display_mode', 'on_capture');

        if ($mode !== 'always' && $this->captureCount() === 0 && ! $this->profiler->hasCaptures()) {
            return false;
        }

        return $this->manager->isCapturing();
    }

    protected function isHtmlResponse(Response $response): bool
    {
        $contentType = (string) $response->headers->get('Content-Type', '');

        return $contentType === '' || str_starts_with(strtolower($contentType), 'text/html');
    }

    protected function captureCount(): int
    {
        return $this->manager->count()
            + $this->manager->timingsCount()
            + $this->manager->milestonesCount();
    }

    /**
     * Stash the current request's captures so the next request can render
     * them. Skipped for Inertia XHR responses — the share callback already
     * ships the same data as `page.props._snip`, so flashing would mean
     * duplicate delivery on the next visit.
     */
    protected function flashPendingForNextRequest(Request $request, SymfonyResponse $response): void
    {
        if (! $this->manager->isCapturing()) {
            return;
        }

        if ($this->captureCount() === 0) {
            return;
        }

        if ($response->headers->has('X-Inertia')) {
            return;
        }

        $status = $response->getStatusCode();

        if ($status < 300 || $status >= 400) {
            return;
        }

        $this->pendingStore->flash($request, $this->manager->payload());
    }
}
