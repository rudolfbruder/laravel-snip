<?php

declare(strict_types=1);

namespace RudolfBruder\LaravelSnip\Http\Renderers;

use Illuminate\Http\Response;
use RudolfBruder\LaravelSnip\Support\BundleAssetResolver;
use RudolfBruder\LaravelSnip\Support\PayloadBuilder;

/**
 * Builds the `<laravel-snip>` web component snippet, splices it into the
 * response body before `</body>`, and locks down the caching headers so
 * the gated payload cannot be served to a different user.
 */
class SnippetRenderer
{
    public function __construct(
        protected PayloadBuilder $payloadBuilder,
        protected BundleAssetResolver $assetResolver,
    ) {}

    public function inject(Response $response): Response
    {
        $payload = $this->encodePayload();

        if ($payload === null) {
            return $response;
        }

        $content = $response->getContent();
        $position = strripos($content, '</body>');

        if ($position === false) {
            return $response;
        }

        $response->setContent(
            substr_replace($content, $this->snippet($payload), $position, 0),
        );

        $this->applyPrivateCacheHeaders($response);

        return $response;
    }

    protected function encodePayload(): ?string
    {
        $data = $this->payloadBuilder->build();

        if ($data === null) {
            return null;
        }

        $payload = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );

        if ($payload === false) {
            report(new \RuntimeException('[laravel-snip] failed to encode payload: '.json_last_error_msg()));

            return null;
        }

        return $payload;
    }

    protected function snippet(string $payload): string
    {
        return sprintf(
            '<laravel-snip data-payload="%s"></laravel-snip><script src="%s" defer></script>',
            e($payload),
            e($this->assetResolver->url()),
        );
    }

    /**
     * The injected HTML carries captures scoped to the gated user, so we
     * force a private no-store cache policy. Without this, a shared cache
     * (Varnish, CDN, response cache) could serve the same HTML to a guest.
     */
    protected function applyPrivateCacheHeaders(Response $response): void
    {
        $response->headers->set('Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');

        $vary = $response->headers->get('Vary');
        $varyParts = $vary ? array_map('trim', explode(',', $vary)) : [];

        if (! in_array('Cookie', $varyParts, true)) {
            $varyParts[] = 'Cookie';
            $response->headers->set('Vary', implode(', ', array_filter($varyParts)));
        }
    }
}
