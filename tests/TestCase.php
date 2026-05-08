<?php

declare(strict_types=1);

namespace RudolfBruder\LaravelSnip\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use RudolfBruder\LaravelSnip\SnipServiceProvider;

abstract class TestCase extends BaseTestCase
{
    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [SnipServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('snip.enabled', true);
    }
}
