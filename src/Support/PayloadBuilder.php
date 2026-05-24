<?php

declare(strict_types=1);

namespace RudolfBruder\LaravelSnip\Support;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use RudolfBruder\LaravelSnip\SnipManager;

/**
 * Builds the JSON-serialisable payload shipped to the frontend panel.
 *
 * Used by:
 *  - InjectSnip middleware → embedded into `<laravel-snip data-payload="…">`
 *    on `text/html` responses.
 *  - The Inertia `share('_snip')` callback in SnipServiceProvider → shipped
 *    inside `page.props._snip` on every Inertia visit so the panel can
 *    refresh on SPA navigations without a full HTML reload.
 *
 * The shape is identical across both channels; the frontend hydrates
 * either way.
 */
class PayloadBuilder
{
    public function __construct(
        protected SnipManager $manager,
        protected ConfigRepository $config,
        protected CacheSnapshot $cacheCollector,
        protected QueueSnapshot $queueCollector,
    ) {}

    /** @return array<string, mixed>|null */
    public function build(): ?array
    {
        $this->manager->ensureHydratedFromSession();

        $data = [
            'snips' => $this->manager->entries(),
            'timings' => $this->manager->timings(),
            'milestones' => $this->manager->milestones(),
            'config' => [
                'datalayer' => (bool) $this->config->get('snip.datalayer', true),
                'cache' => $this->cacheCollector->enabled(),
                'cache_value_url' => $this->cacheCollector->enabled()
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

        if ($this->cacheCollector->enabled()) {
            $snapshot = $this->cacheCollector->capture();

            if ($snapshot !== null) {
                $data['cache'] = $snapshot;
            }
        }

        return $data;
    }
}
