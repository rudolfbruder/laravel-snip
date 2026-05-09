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

        $this->newLine();
        $this->components->warn('Final step — register the provider:');
        $this->line('  • Laravel 10  → add <comment>App\\Providers\\SnipServiceProvider::class</comment> to <info>config/app.php</info> "providers".');
        $this->line('  • Laravel 11+ → add <comment>App\\Providers\\SnipServiceProvider::class</comment> to <info>bootstrap/providers.php</info>.');
        $this->newLine();
        $this->line('Then edit <info>app/Providers/SnipServiceProvider.php</info> and define the <comment>viewSnip</comment> gate.');
        $this->newLine();
        $this->components->info('After every <comment>composer update rudolfbruder/laravel-snip</comment>, run <comment>php artisan snip:publish</comment> to refresh the bundle.');

        return self::SUCCESS;
    }
}
