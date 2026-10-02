<?php

declare(strict_types=1);

namespace RudolfBruder\LaravelSnip\Support;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use RudolfBruder\LaravelSnip\Profiler;
use RudolfBruder\LaravelSnip\SnipManager;
use Symfony\Component\HttpFoundation\Response;

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
 * either way. Only the Inertia channel has no response yet, so its profiler
 * report leaves out the status code and response size.
 */
class PayloadBuilder
{
    public function __construct(
        protected SnipManager $manager,
        protected ConfigRepository $config,
        protected CacheSnapshot $cacheCollector,
        protected QueueSnapshot $queueCollector,
        protected Profiler $profiler,
        protected CapturingDecision $decision,
        protected GuestAccess $guestAccess,
    ) {}

    /** @return array<string, mixed>|null */
    public function build(?Response $response = null): ?array
    {
        $this->manager->ensureHydratedFromSession();

        $data = [
            'snips' => $this->manager->entries(),
            'timings' => $this->manager->timings(),
            'milestones' => $this->manager->milestones(),
            'profile' => $this->profiler->report(request(), $response),
            'config' => [
                'datalayer' => (bool) $this->config->get('snip.datalayer', true),
                'profiler' => $this->profiler->isEnabled(),
                // Only users who pass the gate itself may manage the guest link.
                'guest_link_url' => $this->guestAccess->enabled() && $this->decision->allowsByGate()
                    ? '/'.trim((string) $this->config->get('snip.cache.route_prefix', '_snip'), '/').'/guest-link'
                    : null,
                'guest' => $this->decision->viaGuestLink(),
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
