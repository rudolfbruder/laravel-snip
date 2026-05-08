<?php

declare(strict_types=1);

namespace RudolfBruder\LaravelSnip\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use RudolfBruder\LaravelSnip\SnipManager;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class InjectSnip
{
    public function __construct(
        protected SnipManager $manager,
        protected ConfigRepository $config,
        protected AuthFactory $auth,
        protected UrlGenerator $urlGenerator,
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

        if (! $this->gateAllows()) {
            return false;
        }

        if (! $response instanceof Response) {
            return false;
        }

        $contentType = (string) $response->headers->get('Content-Type', '');
        if ($contentType !== '' && ! str_starts_with(strtolower($contentType), 'text/html')) {
            return false;
        }

        if ($this->manager->count() === 0) {
            return false;
        }

        $content = $response->getContent();
        if ($content === false || $content === '' || ! str_contains($content, '</body>')) {
            return false;
        }

        return true;
    }

    protected function gateAllows(): bool
    {
        $user = $this->auth->guard()->user();

        return Gate::forUser($user)->allows('viewSnip');
    }

    protected function inject(Response $response): Response
    {
        $payload = json_encode(
            $this->manager->entries(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        if ($payload === false) {
            return $response;
        }

        $assetUrl = $this->urlGenerator->to((string) $this->config->get('snip.asset_route', '/vendor/snip/snip.js'));

        $snippet = sprintf(
            '<laravel-snip data-payload="%s"></laravel-snip><script src="%s" defer></script>',
            htmlspecialchars($payload, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($assetUrl, ENT_QUOTES, 'UTF-8'),
        );

        $content = (string) $response->getContent();
        $position = strripos($content, '</body>');

        if ($position === false) {
            return $response;
        }

        $newContent = substr($content, 0, $position).$snippet.substr($content, $position);
        $response->setContent($newContent);

        if ($response->headers->has('Content-Length')) {
            $response->headers->set('Content-Length', (string) strlen($newContent));
        }

        return $response;
    }
}
