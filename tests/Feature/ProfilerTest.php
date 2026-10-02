<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use RudolfBruder\LaravelSnip\Facades\Snip;
use RudolfBruder\LaravelSnip\Profiler;
use RudolfBruder\LaravelSnip\Support\ProfilerListeners;

/** @return array<string, mixed>|null */
function profilerPayload(string $body): ?array
{
    if (! preg_match('/<laravel-snip data-payload="([^"]*)"/', $body, $match)) {
        return null;
    }

    return json_decode(html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'), true);
}

beforeEach(function () {
    config()->set('cache.default', 'array');
    Gate::define('viewSnip', fn ($user = null) => true);

    // Listeners are registered at boot only when the profiler is on, so switch it on and register
    // them by hand for this file.
    config()->set('snip.profiler.enabled', true);
    app(ProfilerListeners::class)->register();

    Route::get('/__profiler-test/measured', function () {
        $profiler = app(Profiler::class);
        $profiler->surface('category', ['categoryId' => 42]);
        $profiler->markControllerStarted();

        $profiler->measure('load products', function () use ($profiler) {
            DB::select('select 1');
            $profiler->measure('load prices', fn () => Cache::get('missing-key'));
        });

        return response('<html><body>products</body></html>')->header('Content-Type', 'text/html');
    });

    Route::get('/__profiler-test/facade', function () {
        Snip::surface('facade', ['page' => 2]);
        Snip::measure('outer', fn () => Snip::measure('inner', fn () => DB::select('select 1')));
        Snip::profiler()->addContext(['extra' => true]);

        return response('<html><body>facade</body></html>')->header('Content-Type', 'text/html');
    });

    Route::get('/__profiler-test/plain', function () {
        return response('<html><body>plain</body></html>')->header('Content-Type', 'text/html');
    });
});

it('ships a text report with phases, nested steps and the calls made in them', function () {
    $data = profilerPayload($this->get('/__profiler-test/measured')->getContent());

    expect($data['config']['profiler'])->toBeTrue()
        ->and($data['profile']['text'])->toBeString();

    $report = $data['profile']['text'];

    expect($report)
        ->toContain('[category] GET http://localhost/__profiler-test/measured 200')
        ->toContain('context: {"categoryId":42}')
        ->toContain('middleware before controller')
        ->toContain('load products  [sql 1 /')
        ->toContain('    load prices')
        ->toContain('cache hit 0 / miss 1 / write 0')
        ->toContain('slowest sql (1 of 1)')
        ->toContain('select 1  (in: load products)');
});

it('ships the same profile as structured data', function () {
    $profile = profilerPayload($this->get('/__profiler-test/measured')->getContent())['profile'];

    expect($profile['surface'])->toBe('category')
        ->and($profile['response']['status'])->toBe(200)
        ->and(array_column($profile['phases'], 'label'))->toContain('controller')
        ->and($profile['steps'][0])->toMatchArray(['label' => 'load products', 'depth' => 0])
        ->and($profile['steps'][0]['counters'][0])->toMatchArray(['kind' => 'sql', 'count' => 1])
        ->and($profile['steps'][1])->toMatchArray(['label' => 'load prices', 'depth' => 1])
        ->and($profile['cache'])->toBe(['hit' => 0, 'miss' => 1, 'write' => 0])
        ->and($profile['queries']['slowest'][0])->toMatchArray(['sql' => 'select 1', 'step' => 'load products']);
});

it('profiles through the Snip facade, marking the controller start and the viewer', function () {
    $profile = profilerPayload($this->get('/__profiler-test/facade')->getContent())['profile'];

    expect($profile['surface'])->toBe('facade')
        ->and($profile['user'])->toBe('guest')
        ->and($profile['context'])->toBe(['page' => 2, 'extra' => true])
        ->and(array_column($profile['phases'], 'label'))->toContain('middleware before controller', 'controller')
        ->and(array_column($profile['steps'], 'label'))->toBe(['outer', 'inner'])
        ->and($profile['steps'][1]['depth'])->toBe(1)
        ->and($profile['text'])->toContain('[facade] GET http://localhost/__profiler-test/facade 200 | guest |');
});

it('injects the panel for a profiled surface even without other captures', function () {
    expect(profilerPayload($this->get('/__profiler-test/measured')->getContent()))->not->toBeNull();
});

it('leaves unprofiled pages without other captures alone', function () {
    expect(profilerPayload($this->get('/__profiler-test/plain')->getContent()))->toBeNull();
});

it('ships no report when the profiler is off', function () {
    config()->set('snip.profiler.enabled', false);
    config()->set('snip.display_mode', 'always');

    $data = profilerPayload($this->get('/__profiler-test/measured')->getContent());

    expect($data['profile'])->toBeNull()
        ->and($data['config']['profiler'])->toBeFalse();
});

it('ships nothing when the gate denies', function () {
    Gate::define('viewSnip', fn ($user = null) => false);

    expect($this->get('/__profiler-test/measured')->getContent())->not->toContain('<laravel-snip');
});

it('only runs the closure when the profiler is not armed', function () {
    $profiler = app(Profiler::class);

    expect($profiler->measure('outside a request', fn () => 'value'))->toBe('value')
        ->and($profiler->hasCaptures())->toBeFalse()
        ->and($profiler->report(request()))->toBeNull();
});
