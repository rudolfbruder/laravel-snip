<?php

declare(strict_types=1);

namespace RudolfBruder\LaravelSnip\Console;

use Illuminate\Console\Command;

class PublishCommand extends Command
{
    protected $signature = 'snip:publish';

    protected $description = 'Re-publish the laravel-snip JS bundle into public/vendor/snip/.';

    public function handle(): int
    {
        $this->callSilently('vendor:publish', ['--tag' => 'snip-assets', '--force' => true]);
        $this->components->info('laravel-snip bundle re-published to public/vendor/snip/snip.js');

        return self::SUCCESS;
    }
}
