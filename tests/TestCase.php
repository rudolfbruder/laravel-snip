<?php

declare(strict_types=1);

namespace RudolfBruder\LaravelSnip\Tests;

use Illuminate\Support\Facades\Gate;
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
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        // Testbench 8 (Laravel 10) defaults to MySQL; later versions default to SQLite. Pin it so
        // the profiler tests' queries run on every supported Laravel version.
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Default gate denies outside the local environment. Tests run in the
        // `testing` env, so allow by default; individual tests can override
        // via `Gate::define('viewSnip', fn () => false)`.
        Gate::define('viewSnip', fn ($user = null): bool => true);
    }
}
