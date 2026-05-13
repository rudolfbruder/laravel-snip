<?php

declare(strict_types=1);

namespace RudolfBruder\LaravelSnip\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use RudolfBruder\LaravelSnip\SnipManager;
use RudolfBruder\LaravelSnip\Support\CacheSnapshot;
use RudolfBruder\LaravelSnip\Support\QueueSnapshot;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class InjectSnip
{
    private static ?int $bundleMtime = null;

    /** @var array<string, mixed>|null */
    protected ?array $cacheSnapshot = null;

    protected bool $cacheSnapshotComputed = false;

    public function __construct(
        protected SnipManager $manager,
        protected ConfigRepository $config,
        protected AuthFactory $auth,
        protected CacheSnapshot $cacheCollector,
        protected QueueSnapshot $queueCollector,
    ) {}

    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        /** @var SymfonyResponse $response */
        $response = $next($request);

        if (! $this->shouldInject($response)) {
            return $response;
        }

        return $this->inject($response);
    }

    protected function shouldInject(SymfonyResponse $response): bool
    {
        if (! $this->config->get('snip.enabled', true)) {
            return false;
        }

        if (! $response instanceof Response) {
            return false;
        }

        $contentType = (string) $response->headers->get('Content-Type', '');
        if ($contentType !== '' && ! str_starts_with(strtolower($contentType), 'text/html')) {
            return false;
        }

        $content = $response->getContent();
        if ($content === false || ! str_contains($content, '</body>')) {
            return false;
        }

        $mode = (string) $this->config->get('snip.display_mode', 'on_capture');

        if ($mode !== 'always' && $this->managerCaptureCount() === 0) {
            return false;
        }

        return $this->gateAllows();
    }

    protected function managerCaptureCount(): int
    {
        return $this->manager->count()
            + $this->manager->timingsCount()
            + $this->manager->milestonesCount();
    }

    protected function cacheEnabled(): bool
    {
        return $this->cacheCollector->enabled();
    }

    /** @return array<string, mixed>|null */
    protected function cacheSnapshot(): ?array
    {
        if ($this->cacheSnapshotComputed) {
            return $this->cacheSnapshot;
        }

        $this->cacheSnapshotComputed = true;

        if (! $this->cacheEnabled()) {
            return $this->cacheSnapshot = null;
        }

        return $this->cacheSnapshot = $this->cacheCollector->capture();
    }

    protected function gateAllows(): bool
    {
        return Gate::forUser($this->resolveUser())->allows('viewSnip');
    }

    protected function resolveUser(): ?Authenticatable
    {
        $guard = $this->config->get('snip.guard');

        return $this->auth->guard($guard)->user();
    }

    protected function inject(Response $response): Response
    {
        $payload = $this->buildPayload();

        if ($payload === null) {
            return $response;
        }

        $snippet = $this->buildSnippet($payload);

        $content = $response->getContent();
        $position = strripos($content, '</body>');

        if ($position === false) {
            return $response;
        }

        $response->setContent(substr_replace($content, $snippet, $position, 0));

        // Force a private, no-store cache policy whenever we inject. This is
        // required because the injected HTML carries captured data scoped to
        // the gated user who triggered the request. Without this header a
        // shared cache (Varnish, CDN, response cache) could serve the same
        // HTML to a guest.
        $response->headers->set('Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');

        $vary = $response->headers->get('Vary');
        $varyParts = $vary ? array_map('trim', explode(',', $vary)) : [];
        if (! in_array('Cookie', $varyParts, true)) {
            $varyParts[] = 'Cookie';
            $response->headers->set('Vary', implode(', ', array_filter($varyParts)));
        }

        return $response;
    }

    protected function buildPayload(): ?string
    {
        $data = [
            'snips' => $this->manager->entries(),
            'timings' => $this->manager->timings(),
            'milestones' => $this->manager->milestones(),
            'config' => [
                'datalayer' => (bool) $this->config->get('snip.datalayer', true),
                'cache' => $this->cacheEnabled(),
                'cache_value_url' => $this->cacheEnabled()
                    ? '/'.trim((string) $this->config->get('snip.cache.route_prefix', '_snip'), '/').'/cache'
                    : null,
                'queue' => $this->queueCollector->enabled(),
                'queue_url' => $this->queueCollector->enabled()
                    ? '/'.trim((string) $this->config->get('snip.cache.route_prefix', '_snip'), '/').'/queue'
                    : null,
                'queue_driver' => $this->queueCollector->enabled() ? $this->queueCollector->activeDriver() : null,
                'queue_supports_listing' => $this->queueCollector->enabled() && $this->queueCollector->driverSupportsListing(),
                'queue_horizon' => $this->queueCollector->enabled() && $this->queueCollector->horizonAvailable(),
            ],
        ];

        $snapshot = $this->cacheSnapshot();
        if ($snapshot !== null) {
            $data['cache'] = $snapshot;
        }

        $payload = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        if ($payload === false) {
            report(new \RuntimeException('[laravel-snip] failed to encode payload: '.json_last_error_msg()));

            return null;
        }

        return $payload;
    }

    protected function buildSnippet(string $payload): string
    {
        return sprintf(
            '<laravel-snip data-payload="%s"></laravel-snip><script src="%s" defer></script>',
            e($payload),
            e($this->assetUrl()),
        );
    }

    protected function assetUrl(): string
    {
        $assetPath = (string) $this->config->get('snip.asset_path', '/vendor/snip/snip.js');

        $mtime = $this->bundleMtime($assetPath);

        return $mtime === null ? $assetPath : $assetPath.'?v='.$mtime;
    }

    /**
     * Resolve the published bundle's last-modified timestamp for cache busting.
     *
     * Used to append `?v=<mtime>` to the script tag so browsers fetch a new
     * `dist/snip.js` after every `vendor:publish --tag=snip-assets`. The
     * value is memoised in a static property so persistent runtimes
     * (Octane, queue workers) don't re-stat the file on every request.
     * Returns null when the bundle has not been published yet — the
     * middleware then emits the script src without a query string.
     */
    protected function bundleMtime(string $assetPath): ?int
    {
        if (self::$bundleMtime !== null) {
            return self::$bundleMtime;
        }

        $publicPath = public_path(ltrim($assetPath, '/'));

        if (! is_file($publicPath)) {
            return null;
        }

        return self::$bundleMtime = (int) filemtime($publicPath);
    }
}
