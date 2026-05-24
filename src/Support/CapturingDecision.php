<?php

declare(strict_types=1);

namespace RudolfBruder\LaravelSnip\Support;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Gate;
use RudolfBruder\LaravelSnip\Snip;

/**
 * Resolves once per request whether the active user is allowed to record
 * and view snip output.
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

    public function __construct(
        protected ConfigRepository $config,
        protected AuthFactory $auth,
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

        $user = $this->auth->guard($this->config->get('snip.guard'))->user();

        return $this->cached = Gate::forUser($user)->allows(Snip::GATE);
    }

    public function reset(): void
    {
        $this->cached = null;
    }
}
