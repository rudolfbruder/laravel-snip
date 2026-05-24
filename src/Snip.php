<?php

declare(strict_types=1);

namespace RudolfBruder\LaravelSnip;

/**
 * Package-wide constants and lookup keys.
 *
 * Lives at the root namespace so any component can reference shared values
 * (session keys, gate names, route names) without pulling in heavier
 * collaborators.
 */
final class Snip
{
    /**
     * Session key used to flash captures across a redirect chain so the
     * panel can hydrate them on the next request that can render HTML or
     * ship them through Inertia's shared props.
     */
    public const PENDING_SESSION_KEY = '_snip.pending';

    /**
     * Inertia shared-prop key. The frontend panel listens for
     * `inertia:success` events and re-hydrates from `page.props[<this key>]`.
     */
    public const INERTIA_SHARE_KEY = '_snip';

    /**
     * Gate ability checked before any panel data is recorded or rendered.
     */
    public const GATE = 'viewSnip';
}
