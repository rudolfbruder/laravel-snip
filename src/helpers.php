<?php

declare(strict_types=1);

use RudolfBruder\LaravelSnip\SnipManager;

if (! function_exists('snip')) {
    /**
     * Capture a value to the request's snip panel.
     *
     * Returns the value unchanged so it can be inlined inside expressions:
     *
     *     return snip(User::find($id), 'lookup')->email;
     */
    function snip(mixed $value, ?string $label = null): mixed
    {
        if (function_exists('app')) {
            app(SnipManager::class)->add($value, $label);
        }

        return $value;
    }
}

if (! function_exists('snip_time')) {
    /**
     * Record an elapsed-time entry in the request's snip panel.
     *
     * Without a prior `Snip::start($label)` mark the elapsed time is
     * measured from request start (`LARAVEL_START`).
     */
    function snip_time(string $label): SnipManager
    {
        return app(SnipManager::class)->timing($label);
    }
}

if (! function_exists('snip_here')) {
    /**
     * Record a milestone breadcrumb in the request's snip panel.
     *
     * A non-fatal alternative to `dd()` for confirming a code path executed.
     */
    function snip_here(string $label): SnipManager
    {
        return app(SnipManager::class)->milestone($label);
    }
}
