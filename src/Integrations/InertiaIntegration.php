<?php

declare(strict_types=1);

namespace RudolfBruder\LaravelSnip\Integrations;

use Illuminate\Contracts\Container\Container;
use Inertia\Inertia;
use RudolfBruder\LaravelSnip\Snip;
use RudolfBruder\LaravelSnip\SnipManager;
use RudolfBruder\LaravelSnip\Support\PayloadBuilder;

/**
 * Bridges the snip panel with Inertia v2.
 *
 * On every Inertia visit — initial HTML and subsequent XHR navigations —
 * the share callback ships the current request's snip payload as
 * `page.props._snip`. The frontend panel subscribes to `inertia:success`
 * (see `resources/js/SnipPanel.ce.vue`) and re-renders from that prop on
 * each SPA navigation, so users no longer need a full page reload to see
 * captures recorded on subsequent Inertia requests.
 *
 * The closure is lazy: it only resolves when Inertia actually renders.
 * It returns null when the gate denies, so unauthorized visitors never
 * receive panel data.
 */
class InertiaIntegration
{
    public function __construct(
        protected Container $app,
    ) {}

    public function isAvailable(): bool
    {
        return class_exists(Inertia::class);
    }

    public function register(): void
    {
        if (! $this->isAvailable()) {
            return;
        }

        Inertia::share(Snip::INERTIA_SHARE_KEY, function () {
            $manager = $this->app->make(SnipManager::class);

            if (! $manager->isCapturing()) {
                return null;
            }

            return $this->app->make(PayloadBuilder::class)->build();
        });
    }
}
