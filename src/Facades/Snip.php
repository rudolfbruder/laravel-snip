<?php

declare(strict_types=1);

namespace RudolfBruder\LaravelSnip\Facades;

use Illuminate\Support\Facades\Facade;
use RudolfBruder\LaravelSnip\SnipManager;

/**
 * @method static SnipManager add(mixed $value, ?string $label = null)
 * @method static SnipManager start(string $label)
 * @method static SnipManager timing(string $label)
 * @method static SnipManager milestone(string $label)
 * @method static SnipManager replay(array $payload)
 * @method static SnipManager ensureHydratedFromSession(?\Illuminate\Http\Request $request = null)
 * @method static SnipManager clear()
 * @method static array entries()
 * @method static array timings()
 * @method static array milestones()
 * @method static array payload()
 * @method static int count()
 * @method static int timingsCount()
 * @method static int milestonesCount()
 * @method static bool enabled()
 * @method static bool isCapturing()
 *
 * @see SnipManager
 */
class Snip extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SnipManager::class;
    }
}
