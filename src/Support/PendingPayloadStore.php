<?php

declare(strict_types=1);

namespace RudolfBruder\LaravelSnip\Support;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use RudolfBruder\LaravelSnip\Snip;

/**
 * Reads and writes the per-request snip payload that is flashed across
 * a redirect chain via the session.
 *
 * Plain HTTP redirects (3xx without `X-Inertia`) prevent the middleware
 * from injecting the panel inline, so we stash the captured payload in
 * the session and replay it on the next request that *can* render the
 * panel — either via `<laravel-snip data-payload="…">` (full HTML) or
 * Inertia's shared `_snip` prop (XHR navigations).
 *
 * Inertia XHR responses are deliberately skipped here because the
 * payload is already shipped through the share callback.
 */
class PendingPayloadStore
{
    public function __construct(
        protected ConfigRepository $config,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->config->get('snip.pending_redirects', true);
    }

    /** @return array{entries?: array, timings?: array, milestones?: array}|null */
    public function pull(Request $request): ?array
    {
        if (! $this->canAccess($request)) {
            return null;
        }

        $session = $request->session();

        if (! $session->has(Snip::PENDING_SESSION_KEY)) {
            return null;
        }

        return (array) $session->pull(Snip::PENDING_SESSION_KEY);
    }

    public function forget(Request $request): void
    {
        if (! $this->canAccess($request)) {
            return;
        }

        $request->session()->forget(Snip::PENDING_SESSION_KEY);
    }

    /** @param array{entries?: array, timings?: array, milestones?: array} $payload */
    public function flash(Request $request, array $payload): void
    {
        if (! $this->canAccess($request)) {
            return;
        }

        $request->session()->flash(Snip::PENDING_SESSION_KEY, $payload);
    }

    protected function canAccess(Request $request): bool
    {
        return $this->enabled() && $request->hasSession();
    }
}
