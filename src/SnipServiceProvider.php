<?php

declare(strict_types=1);

namespace RudolfBruder\LaravelSnip;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use RudolfBruder\LaravelSnip\Http\Middleware\InjectSnip;
use RudolfBruder\LaravelSnip\Support\SnipDumper;

class SnipServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/snip.php', 'snip');

        $this->app->singleton(SnipDumper::class);
        $this->app->singleton(SnipManager::class);
    }

    public function boot(): void
    {
        $this->registerDefaultGate();
        $this->registerMiddleware();
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/snip.php' => config_path('snip.php'),
            ], 'snip-config');
        }
    }

    protected function registerDefaultGate(): void
    {
        if (Gate::has('viewSnip')) {
            return;
        }

        Gate::define('viewSnip', fn ($user = null): bool => $this->app->environment('local'));
    }

    protected function registerMiddleware(): void
    {
        $kernel = $this->app->make(HttpKernel::class);

        if (method_exists($kernel, 'pushMiddleware')) {
            $kernel->pushMiddleware(InjectSnip::class);

            return;
        }

        $router = $this->app->make(Router::class);
        $router->pushMiddlewareToGroup('web', InjectSnip::class);
    }
}
