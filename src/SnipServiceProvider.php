<?php

declare(strict_types=1);

namespace RudolfBruder\LaravelSnip;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use RudolfBruder\LaravelSnip\Console\InstallCommand;
use RudolfBruder\LaravelSnip\Console\PublishCommand;
use RudolfBruder\LaravelSnip\Http\Controllers\CacheValueController;
use RudolfBruder\LaravelSnip\Http\Controllers\QueueController;
use RudolfBruder\LaravelSnip\Http\Middleware\InjectSnip;
use RudolfBruder\LaravelSnip\Support\CacheSnapshot;
use RudolfBruder\LaravelSnip\Support\QueueSnapshot;
use RudolfBruder\LaravelSnip\Support\SnipDumper;

class SnipServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/snip.php', 'snip');

        // scoped() resets per request, including in Octane / queue / fpm.
        // Prevents captures from one request leaking into the next when the
        // PHP process is long-lived.
        $this->app->scoped(SnipDumper::class);
        $this->app->scoped(SnipManager::class);
        $this->app->scoped(CacheSnapshot::class);
        $this->app->scoped(QueueSnapshot::class);
    }

    public function boot(): void
    {
        $this->registerDefaultGate();
        $this->registerMiddleware();
        $this->registerRoutes();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/snip.php' => config_path('snip.php'),
            ], 'snip-config');

            $this->publishes([
                __DIR__ . '/../stubs/SnipServiceProvider.stub' => app_path('Providers/SnipServiceProvider.php'),
            ], 'snip-provider');

            $this->publishes([
                __DIR__ . '/../dist/snip.js' => public_path('vendor/snip/snip.js'),
            ], 'snip-assets');

            $this->commands([
                InstallCommand::class,
                PublishCommand::class,
            ]);
        }
    }

    protected function registerDefaultGate(): void
    {
        if (Gate::has('viewSnip')) {
            return;
        }

        Gate::define('viewSnip', fn ($user = null): bool => $this->app->environment('local'));
    }

    protected function registerRoutes(): void
    {
        Route::middleware('web')
            ->prefix((string) $this->app['config']->get('snip.cache.route_prefix', '_snip'))
            ->group(function (): void {
                Route::get('/cache', [CacheValueController::class, 'show'])
                    ->name('snip.cache.show');

                Route::get('/queue', [QueueController::class, 'index'])
                    ->name('snip.queue.index');
            });
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
