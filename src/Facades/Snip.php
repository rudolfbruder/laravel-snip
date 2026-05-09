<?php

declare(strict_types=1);

namespace RudolfBruder\LaravelSnip\Facades;

use Illuminate\Support\Facades\Facade;
use RudolfBruder\LaravelSnip\SnipManager;

/**
 * @method static \RudolfBruder\LaravelSnip\SnipManager add(mixed $value, ?string $label = null)
 * @method static array entries()
 * @method static int count()
 * @method static \RudolfBruder\LaravelSnip\SnipManager start(string $label)
 * @method static \RudolfBruder\LaravelSnip\SnipManager timing(string $label)
 * @method static array timings()
 * @method static int timingsCount()
 * @method static \RudolfBruder\LaravelSnip\SnipManager milestone(string $label)
 * @method static array milestones()
 * @method static int milestonesCount()
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
