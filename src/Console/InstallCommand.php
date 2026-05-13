<?php

declare(strict_types=1);

namespace RudolfBruder\LaravelSnip\Console;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

class InstallCommand extends Command
{
    protected $signature = 'snip:install';

    protected $description = 'Install the laravel-snip provider stub, publish the JS bundle, and configure .env.';

    public function handle(): int
    {
        info('Publishing laravel-snip resources…');

        $this->callSilently('vendor:publish', ['--tag' => 'snip-provider']);
        $this->components->task('SnipServiceProvider stub copied to app/Providers/');

        $this->callSilently('vendor:publish', ['--tag' => 'snip-assets', '--force' => true]);
        $this->components->task('Compiled bundle copied to public/vendor/snip/snip.js');

        $env = $this->collectEnv();

        warning('Final step — register the provider:');
        $this->line('  • Laravel 10  → add <comment>App\\Providers\\SnipServiceProvider::class</comment> to <info>config/app.php</info> "providers".');
        $this->line('  • Laravel 11+ → add <comment>App\\Providers\\SnipServiceProvider::class</comment> to <info>bootstrap/providers.php</info>.');
        $this->newLine();
        $this->line('Then edit <info>app/Providers/SnipServiceProvider.php</info> and define the <comment>viewSnip</comment> gate.');
        $this->newLine();

        if ($env === []) {
            info('No env changes needed — defaults are fine for your selections.');
        } else {
            $this->renderEnvBlock($env);
        }

        info('After every `composer update rudolfbruder/laravel-snip`, run `php artisan snip:publish` to refresh the bundle.');

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $env
     */
    protected function renderEnvBlock(array $env): void
    {
        $block = implode("\n", $env);
        $width = max(array_map('mb_strlen', $env)) + 4;
        $width = max($width, 30);
        $bar = str_repeat('─', $width);

        $this->newLine();
        $this->line('  <fg=cyan>┌─ .env '.str_repeat('─', $width - 7).'┐</>');
        foreach ($env as $line) {
            $pad = str_repeat(' ', $width - mb_strlen($line) - 2);
            $this->line('  <fg=cyan>│</>  <comment>'.$line.'</comment>'.$pad.'<fg=cyan>│</>');
        }
        $this->line('  <fg=cyan>└'.$bar.'┘</>');
        $this->newLine();

        if (confirm('Copy to clipboard?', default: true)) {
            if ($this->copyToClipboard($block)) {
                info('✓ Copied to clipboard — paste into .env.');

                return;
            }

            warning('No clipboard tool detected (pbcopy / xclip / wl-copy / clip.exe).');
        }
    }

    protected function copyToClipboard(string $text): bool
    {
        $candidates = match (PHP_OS_FAMILY) {
            'Darwin' => [['pbcopy']],
            'Windows' => [['clip']],
            default => [
                ['wl-copy'],
                ['xclip', '-selection', 'clipboard'],
                ['xsel', '--clipboard', '--input'],
            ],
        };

        foreach ($candidates as $cmd) {
            try {
                $process = new Process($cmd);
                $process->setInput($text);
                $process->run();
                if ($process->isSuccessful()) {
                    return true;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    protected function collectEnv(): array
    {
        $env = [];

        info('Configure laravel-snip — accept defaults by pressing enter.');

        $mode = select(
            label: 'Panel display mode',
            options: [
                'on_capture' => 'on_capture · only when Snip::add / timing / milestone fires (default, lightweight)',
                'always' => 'always · every gated HTML response (Cache / Queue tabs reachable anywhere)',
            ],
            default: 'on_capture',
        );
        if ($mode !== 'on_capture') {
            $env[] = 'SNIP_DISPLAY_MODE='.$mode;
        }

        if (! confirm('Enable the Cache tab?', default: true)) {
            $env[] = 'SNIP_CACHE=false';
        }

        if (! confirm('Enable the Queue tab?', default: true)) {
            $env[] = 'SNIP_QUEUE=false';
        }

        if (! confirm('Enable the DataLayer tab (GTM events)?', default: true)) {
            $env[] = 'SNIP_DATALAYER=false';
        }

        if (! confirm('Show per-snip memory footprint in the panel?', default: true)) {
            $env[] = 'SNIP_SHOW_MEMORY=false';
        }

        if (confirm('Configure advanced options (auth guard, route prefix, redis prefix)?', default: false)) {
            $guard = text(
                label: 'Auth guard for the viewSnip gate',
                placeholder: 'leave blank for framework default',
                default: '',
            );
            if ($guard !== '') {
                $env[] = 'SNIP_GUARD='.$guard;
            }

            $routePrefix = text(
                label: 'URL prefix for the cache/queue endpoints',
                default: '_snip',
            );
            if ($routePrefix !== '_snip' && $routePrefix !== '') {
                $env[] = 'SNIP_ROUTE_PREFIX='.$routePrefix;
            }

            if (! confirm('Scan only redis keys with the cache prefix (recommended)?', default: true)) {
                $env[] = 'SNIP_CACHE_MATCH_PREFIX=false';
            }

            $cachePrefix = text(
                label: 'Override the redis cache prefix detected from RedisStore::getPrefix()',
                placeholder: 'leave blank to auto-detect',
                default: '',
            );
            if ($cachePrefix !== '') {
                $env[] = 'SNIP_CACHE_PREFIX='.$cachePrefix;
            }
        }

        return $env;
    }
}
