<?php

declare(strict_types=1);

namespace RudolfBruder\LaravelSnip\Support;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Guest links: lets a user who passes the `viewSnip` gate hand the panel to a browser that is not
 * logged in (a colleague, a phone, an incognito window).
 *
 * There is one active link at a time. Its token lives in the application cache with a TTL, and the
 * browser that opens the link gets the token in a cookie. The cookie only grants access while the
 * cached token still matches, so revoking or replacing the link, or letting it expire, locks every
 * guest out again — the cookie value on its own is worth nothing.
 */
class GuestAccess
{
    public const COOKIE = 'snip_guest';

    public const CACHE_KEY = 'snip:guest-link';

    public function __construct(
        protected CacheFactory $cache,
        protected ConfigRepository $config,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->config->get('snip.guest_links.enabled', true);
    }

    /** @return array{token: string, expires_at: int, created_at: int, created_by: string}|null */
    public function current(): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $link = $this->cache->store()->get(self::CACHE_KEY);

        if (! is_array($link) || ! is_string($link['token'] ?? null) || ($link['expires_at'] ?? 0) <= time()) {
            return null;
        }

        return $link;
    }

    /**
     * Replaces the active link, so the previous one stops working.
     *
     * @param  string  $createdBy  who created it, for the panel and the log
     * @return array{token: string, expires_at: int, created_at: int, created_by: string}
     */
    public function create(string $createdBy): array
    {
        $ttl = max(1, (int) $this->config->get('snip.guest_links.ttl', 120)) * 60;
        $link = [
            'token' => (string) Str::uuid(),
            'expires_at' => time() + $ttl,
            'created_at' => time(),
            'created_by' => $createdBy,
        ];

        $this->cache->store()->put(self::CACHE_KEY, $link, $ttl);

        return $link;
    }

    public function revoke(): void
    {
        $this->cache->store()->forget(self::CACHE_KEY);
    }

    /**
     * A short, one-way fingerprint of the token: enough to tell links apart in the log, useless for
     * rebuilding the link.
     */
    public function fingerprint(string $token): string
    {
        return substr(hash('sha256', $token), 0, 12);
    }

    public function accepts(string $token): bool
    {
        $link = $this->current();

        return $link !== null && hash_equals($link['token'], $token);
    }

    public function grants(?Request $request): bool
    {
        $token = $request?->cookie(self::COOKIE);

        return is_string($token) && $token !== '' && $this->accepts($token);
    }

    /** @param  array{token: string, expires_at: int, created_at: int, created_by: string}  $link */
    public function cookie(array $link, Request $request): Cookie
    {
        return Cookie::create(
            name: self::COOKIE,
            value: $link['token'],
            expire: $link['expires_at'],
            secure: $request->isSecure(),
            httpOnly: true,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }
}
