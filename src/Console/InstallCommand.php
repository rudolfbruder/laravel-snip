<?php

declare(strict_types=1);

namespace RudolfBruder\LaravelSnip\Console;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'snip:install';

    protected $description = 'Install the laravel-snip provider stub and publish the JS bundle.';

    public function handle(): int
    {
        $this->components->info('Publishing the laravel-snip resources.');

        $this->callSilently('vendor:publish', ['--tag' => 'snip-provider']);
        $this->components->task('SnipServiceProvider stub copied to app/Providers/');

        $this->callSilently('vendor:publish', ['--tag' => 'snip-assets', '--force' => true]);
        $this->components->task('Compiled bundle copied to public/vendor/snip/snip.js');

        $env = $this->collectEnv();

        $this->newLine();
        $this->components->warn('Final step — register the provider:');
        $this->line('  • Laravel 10  → add <comment>App\\Providers\\SnipServiceProvider::class</comment> to <info>config/app.php</info> "providers".');
        $this->line('  • Laravel 11+ → add <comment>App\\Providers\\SnipServiceProvider::class</comment> to <info>bootstrap/providers.php</info>.');
        $this->newLine();
        $this->line('Then edit <info>app/Providers/SnipServiceProvider.php</info> and define the <comment>viewSnip</comment> gate.');
        $this->newLine();

        if ($env === []) {
            $this->components->info('No env changes needed — defaults are fine for your selections.');
        } else {
            $this->components->info('Add this block to your <comment>.env</comment>:');
            $this->line('');
            foreach ($env as $line) {
                $this->line('  <comment>'.$line.'</comment>');
            }
            $this->line('');
        }

        $this->components->info('After every <comment>composer update rudolfbruder/laravel-snip</comment>, run <comment>php artisan snip:publish</comment> to refresh the bundle.');

        return self::SUCCESS;
    }

    /**
     * Walk the user through the configurable options and return only the
     * env lines that differ from the package's built-in defaults.
     *
     * @return array<int, string>
     */
    protected function collectEnv(): array
    {
        $env = [];

        $this->newLine();
        $this->components->info('Configure laravel-snip — accept defaults by pressing enter.');

        $mode = $this->choice(
            'Panel display mode',
            [
                'on_capture' => 'on_capture — only on requests that called Snip::add / timing / milestone (default)',
                'always' => 'always — every gated HTML response (Cache / Queue tabs reachable anywhere)',
            ],
            'on_capture'
        );
        if ($mode !== 'on_capture') {
            $env[] = 'SNIP_DISPLAY_MODE='.$mode;
        }

        if (! $this->confirm('Enable the Cache tab?', true)) {
            $env[] = 'SNIP_CACHE=false';
        }

        if (! $this->confirm('Enable the Queue tab?', true)) {
            $env[] = 'SNIP_QUEUE=false';
        }

        if (! $this->confirm('Enable the DataLayer tab (GTM events)?', true)) {
            $env[] = 'SNIP_DATALAYER=false';
        }

        if (! $this->confirm('Show per-snip memory footprint in the panel?', true)) {
            $env[] = 'SNIP_SHOW_MEMORY=false';
        }

        if ($this->confirm('Configure advanced options (auth guard, route prefix, redis prefix)?', false)) {
            $guard = (string) $this->ask('Auth guard for the viewSnip gate (leave blank for framework default)', '');
            if ($guard !== '') {
                $env[] = 'SNIP_GUARD='.$guard;
            }

            $routePrefix = (string) $this->ask('URL prefix for the cache/queue endpoints', '_snip');
            if ($routePrefix !== '_snip' && $routePrefix !== '') {
                $env[] = 'SNIP_ROUTE_PREFIX='.$routePrefix;
            }

            if (! $this->confirm('Scan only redis keys with the cache prefix (recommended)?', true)) {
                $env[] = 'SNIP_CACHE_MATCH_PREFIX=false';
            }

            $cachePrefix = (string) $this->ask('Override the redis cache prefix detected from RedisStore::getPrefix() (blank = auto)', '');
            if ($cachePrefix !== '') {
                $env[] = 'SNIP_CACHE_PREFIX='.$cachePrefix;
            }
        }

        return $env;
    }
}
