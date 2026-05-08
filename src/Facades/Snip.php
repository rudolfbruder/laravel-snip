<?php

declare(strict_types=1);

namespace RudolfBruder\LaravelSnip\Facades;

use Illuminate\Support\Facades\Facade;
use RudolfBruder\LaravelSnip\SnipManager;

/**
 * @method static \RudolfBruder\LaravelSnip\SnipManager add(mixed $value, ?string $label = null)
 * @method static array entries()
 * @method static int count()
 * @method static \RudolfBruder\LaravelSnip\SnipManager clear()
 * @method static bool enabled()
 *
 * @see \RudolfBruder\LaravelSnip\SnipManager
 */
class Snip extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SnipManager::class;
    }
}
