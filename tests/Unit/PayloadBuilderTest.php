<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use RudolfBruder\LaravelSnip\SnipManager;
use RudolfBruder\LaravelSnip\Support\PayloadBuilder;

beforeEach(function () {
    Gate::define('viewSnip', fn ($user = null) => true);
    app(SnipManager::class)->clear();
});

it('builds payload with snips, timings, milestones, and config block', function () {
    $manager = app(SnipManager::class);
    $manager->add(['k' => 'v'], 'builder-snip');
    $manager->milestone('builder-milestone');

    $payload = app(PayloadBuilder::class)->build();

    expect($payload)->toBeArray()
        ->toHaveKeys(['snips', 'timings', 'milestones', 'config'])
        ->and($payload['snips'])->toHaveCount(1)
        ->and($payload['snips'][0]['label'])->toBe('builder-snip')
        ->and($payload['milestones'])->toHaveCount(1)
        ->and($payload['config'])->toHaveKey('datalayer');
});

it('hydrates the manager from session before building so carried payloads ship', function () {
    $session = app('session.store');
    $session->put(\RudolfBruder\LaravelSnip\Snip::PENDING_SESSION_KEY, [
        'entries' => [['label' => 'pre-flashed', 'file' => null, 'line' => null, 'time_ms' => 1.0, 'bytes' => null, 'value' => []]],
        'timings' => [],
        'milestones' => [],
    ]);

    $request = \Illuminate\Http\Request::create('/');
    $request->setLaravelSession($session);
    app()->instance('request', $request);

    $payload = app(PayloadBuilder::class)->build();

    expect(collect($payload['snips'])->pluck('label')->all())->toContain('pre-flashed');
});
