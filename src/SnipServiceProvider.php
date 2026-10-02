<?php

declare(strict_types=1);

namespace RudolfBruder\LaravelSnip;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use RudolfBruder\LaravelSnip\Console\InstallCommand;
use RudolfBruder\LaravelSnip\Console\PublishCommand;
use RudolfBruder\LaravelSnip\Http\Controllers\CacheValueController;
use RudolfBruder\LaravelSnip\Http\Controllers\GuestLinkController;
use RudolfBruder\LaravelSnip\Http\Controllers\QueueController;
use RudolfBruder\LaravelSnip\Http\Middleware\InjectSnip;
use RudolfBruder\LaravelSnip\Http\Renderers\SnippetRenderer;
use RudolfBruder\LaravelSnip\Integrations\InertiaIntegration;
use RudolfBruder\LaravelSnip\Support\BundleAssetResolver;
use RudolfBruder\LaravelSnip\Support\CacheSnapshot;
use RudolfBruder\LaravelSnip\Support\CallerResolver;
use RudolfBruder\LaravelSnip\Support\CapturingDecision;
use RudolfBruder\LaravelSnip\Support\GuestAccess;
use RudolfBruder\LaravelSnip\Support\PayloadBuilder;
use RudolfBruder\LaravelSnip\Support\PendingPayloadStore;
use RudolfBruder\LaravelSnip\Support\ProfilerListeners;
use RudolfBruder\LaravelSnip\Support\QueueSnapshot;
use RudolfBruder\LaravelSnip\Support\SnipDumper;

class SnipServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/snip.php', 'snip');

        $this->registerScopedBindings();
    }

    public function boot(): void
    {
        $this->registerDefaultGate();
        $this->registerMiddleware();
        $this->registerRoutes();
        $this->app->make(InertiaIntegration::class)->register();
        $this->registerProfiler();

        if ($this->app->runningInConsole()) {
            $this->registerPublishing();
            $this->registerCommands();
        }
    }

    /**
     * Scoped bindings reset per request — including under Octane and
     * queue workers — so captures from one request never leak into the
     * next when the PHP process is long-lived.
     */
    protected function registerScopedBindings(): void
    {
        $this->app->scoped(SnipDumper::class);
        $this->app->scoped(SnipManager::class);
        $this->app->scoped(Profiler::class);
        $this->app->scoped(CacheSnapshot::class);
        $this->app->scoped(QueueSnapshot::class);
        $this->app->scoped(PayloadBuilder::class);
        $this->app->scoped(CapturingDecision::class);
        $this->app->scoped(GuestAccess::class);
        $this->app->scoped(PendingPayloadStore::class);
        $this->app->scoped(CallerResolver::class);
        $this->app->scoped(SnippetRenderer::class);
        $this->app->scoped(BundleAssetResolver::class);
        $this->app->scoped(InertiaIntegration::class);
    }

    protected function registerProfiler(): void
    {
        if ($this->app->make(Profiler::class)->configured()) {
            $this->app->make(ProfilerListeners::class)->register();
        }
    }

    protected function registerDefaultGate(): void
    {
        if (Gate::has(Snip::GATE)) {
            return;
        }

        Gate::define(Snip::GATE, fn ($user = null): bool => $this->app->environment('local'));
    }

    protected function registerMiddleware(): void
    {
        $this->app->make(HttpKernel::class)->pushMiddleware(InjectSnip::class);
    }

    protected function registerRoutes(): void
    {
        $prefix = (string) $this->app['config']->get('snip.cache.route_prefix', '_snip');

        Route::middleware('web')
            ->prefix($prefix)
            ->group(function (): void {
                Route::get('/cache', [CacheValueController::class, 'show'])->name('snip.cache.show');
                Route::get('/queue', [QueueController::class, 'index'])->name('snip.queue.index');
                Route::get('/guest-link', [GuestLinkController::class, 'show'])->name('snip.guest-link.show');
                Route::post('/guest-link', [GuestLinkController::class, 'store'])->name('snip.guest-link.store');
                Route::delete('/guest-link', [GuestLinkController::class, 'destroy'])->name('snip.guest-link.destroy');
                Route::get('/guest/{token}', [GuestLinkController::class, 'enter'])->name('snip.guest.enter');
            });
    }

    protected function registerPublishing(): void
    {
        $this->publishes([
            __DIR__.'/../config/snip.php' => config_path('snip.php'),
        ], 'snip-config');

        $this->publishes([
            __DIR__.'/../stubs/SnipServiceProvider.stub' => app_path('Providers/SnipServiceProvider.php'),
        ], 'snip-provider');

        $this->publishes([
            __DIR__.'/../dist/snip.js' => public_path('vendor/snip/snip.js'),
        ], 'snip-assets');
    }

    protected function registerCommands(): void
    {
        $this->commands([
            InstallCommand::class,
            PublishCommand::class,
        ]);
    }
}
