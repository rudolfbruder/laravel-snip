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
