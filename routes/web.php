<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use RudolfBruder\LaravelSnip\Http\Controllers\SnipAssetController;

Route::get(config('snip.asset_route', '/vendor/snip/snip.js'), SnipAssetController::class)
    ->name('snip.asset');
