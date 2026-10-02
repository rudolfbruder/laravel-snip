<?php

declare(strict_types=1);

namespace RudolfBruder\LaravelSnip\Support;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Gate;
use RudolfBruder\LaravelSnip\Snip;

/**
 * Resolves once per request whether the active user is allowed to record
 * and view snip output: either they pass the `viewSnip` gate, or their
 * browser carries a valid guest-link cookie (see GuestAccess).
 *
 * The decision is memoised because every `snip()` / `Snip::timing()` /
 * `Snip::milestone()` call short-circuits through it. Without the cache, a
 * code path peppered with hundreds of snip calls would re-evaluate the
 * gate and pull the authenticated user on every call.
 *
 * Scoped per request via the service container so long-lived runtimes
 * (Octane, queue workers) don't leak the decision between requests.
 */
class CapturingDecision
{
    protected ?bool $cached = null;

    protected ?bool $gateAllows = null;

    public function __construct(
        protected ConfigRepository $config,
        protected AuthFactory $auth,
        protected GuestAccess $guestAccess,
        protected Container $app,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->config->get('snip.enabled', true);
    }

    public function allows(): bool
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        if (! $this->enabled()) {
            return $this->cached = false;
        }

        return $this->cached = $this->allowsByGate()
            || $this->guestAccess->grants($this->app->bound('request') ? $this->app->make('request') : null);
    }

    /**
     * Whether the user passes the gate itself, not just through a guest link. Only these users may
     * manage guest links.
     */
    public function allowsByGate(): bool
    {
        if ($this->gateAllows !== null) {
            return $this->gateAllows;
        }

        if (! $this->enabled()) {
            return $this->gateAllows = false;
        }

        $user = $this->auth->guard($this->config->get('snip.guard'))->user();

        return $this->gateAllows = Gate::forUser($user)->allows(Snip::GATE);
    }

    public function viaGuestLink(): bool
    {
        return $this->allows() && ! $this->allowsByGate();
    }

    public function reset(): void
    {
        $this->cached = null;
        $this->gateAllows = null;
    }
}
