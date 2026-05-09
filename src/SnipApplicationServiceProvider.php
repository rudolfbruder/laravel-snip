<?php

declare(strict_types=1);

namespace RudolfBruder\LaravelSnip;

use Illuminate\Support\ServiceProvider;

abstract class SnipApplicationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->gate();
    }

    /**
     * Register the Snip gate.
     *
     * Subclasses override this method to define who can view the on-page
     * snip panel. By default no one is allowed.
     */
    protected function gate(): void
    {
        //
    }
}
