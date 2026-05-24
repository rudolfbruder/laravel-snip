<?php

declare(strict_types=1);

namespace RudolfBruder\LaravelSnip\Support;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Resolves the public URL of the published frontend bundle and appends a
 * cache-busting query string based on the bundle's last-modified time.
 *
 * The mtime is memoised in a static property so persistent runtimes
 * (Octane, queue workers) don't re-stat the file on every request.
 */
class BundleAssetResolver
{
    private static ?int $bundleMtime = null;

    public function __construct(
        protected ConfigRepository $config,
    ) {}

    public function url(): string
    {
        $assetPath = (string) $this->config->get('snip.asset_path', '/vendor/snip/snip.js');

        $mtime = $this->bundleMtime($assetPath);

        return $mtime === null ? $assetPath : $assetPath.'?v='.$mtime;
    }

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
