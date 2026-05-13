<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use RudolfBruder\LaravelSnip\SnipManager;

beforeEach(function () {
    Gate::define('viewSnip', fn ($user = null) => true);

    Route::get('/__snip-test/html', function () {
        snip(['hello' => 'world'], 'demo');

        return response('<html><body><h1>Hi</h1></body></html>')
            ->header('Content-Type', 'text/html');
    });

    Route::get('/__snip-test/json', function () {
        snip(['hello' => 'world'], 'demo');

        return response()->json(['ok' => true]);
    });

    Route::get('/__snip-test/no-snip', function () {
        return response('<html><body>nothing here</body></html>')
            ->header('Content-Type', 'text/html');
    });

    Route::get('/__snip-test/timing-only', function () {
        app(SnipManager::class)->timing('checkpoint');

        return response('<html><body>only timing</body></html>')
            ->header('Content-Type', 'text/html');
    });

    Route::get('/__snip-test/milestone-only', function () {
        app(SnipManager::class)->milestone('alpha');

        return response('<html><body>only milestone</body></html>')
            ->header('Content-Type', 'text/html');
    });
});

it('injects the web component before </body> for HTML responses', function () {
    $response = $this->get('/__snip-test/html');

    $response->assertOk();

    $body = $response->getContent();

    expect($body)->toContain('<laravel-snip data-payload=')
        ->and($body)->toContain('snip.js')
        ->and($body)->toContain('</body>');

    $bodyClose = strrpos($body, '</body>');
    $componentPos = strrpos($body, '<laravel-snip');

    expect($componentPos)->toBeLessThan($bodyClose);
});

it('does not inject when there are no snip entries', function () {
    $response = $this->get('/__snip-test/no-snip');

    expect($response->getContent())->not->toContain('<laravel-snip');
});

it('does not inject into JSON responses', function () {
    $response = $this->get('/__snip-test/json');

    $response->assertOk();
    expect($response->getContent())->not->toContain('<laravel-snip');
});

it('does not inject when gate denies', function () {
    Gate::define('viewSnip', fn ($user = null) => false);

    $response = $this->get('/__snip-test/html');

    expect($response->getContent())->not->toContain('<laravel-snip');
});

it('does not inject when snip is disabled', function () {
    config()->set('snip.enabled', false);

    $response = $this->get('/__snip-test/html');

    expect($response->getContent())->not->toContain('<laravel-snip');
});

it('emits a JSON-decodable payload via the data attribute with snips, timings, and milestones keys', function () {
    $response = $this->get('/__snip-test/html');

    $body = $response->getContent();

    preg_match('/<laravel-snip data-payload="([^"]*)"/', $body, $match);
    expect($match[1] ?? null)->not->toBeNull();

    $decoded = html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
    $data = json_decode($decoded, true);

    expect($data)->toBeArray()
        ->toHaveKeys(['snips', 'timings', 'milestones'])
        ->and($data['snips'][0]['label'])->toBe('demo')
        ->and($data['snips'][0]['value']['children'][0]['key'])->toBe('hello')
        ->and($data['timings'])->toBe([])
        ->and($data['milestones'])->toBe([]);
});

it('injects when only timings are present (no snips)', function () {
    $response = $this->get('/__snip-test/timing-only');

    $body = $response->getContent();

    expect($body)->toContain('<laravel-snip data-payload=');

    preg_match('/<laravel-snip data-payload="([^"]*)"/', $body, $match);
    $decoded = html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
    $data = json_decode($decoded, true);

    expect($data['snips'])->toBe([])
        ->and($data['timings'])->toHaveCount(1)
        ->and($data['timings'][0]['label'])->toBe('checkpoint')
        ->and($data['timings'][0]['duration_ms'])->toBeNumeric()
        ->and($data['milestones'])->toBe([]);
});

it('injects when only milestones are present (no snips, no timings)', function () {
    $response = $this->get('/__snip-test/milestone-only');

    $body = $response->getContent();

    expect($body)->toContain('<laravel-snip data-payload=');

    preg_match('/<laravel-snip data-payload="([^"]*)"/', $body, $match);
    $decoded = html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
    $data = json_decode($decoded, true);

    expect($data['snips'])->toBe([])
        ->and($data['timings'])->toBe([])
        ->and($data['milestones'])->toHaveCount(1)
        ->and($data['milestones'][0]['label'])->toBe('alpha')
        ->and($data['milestones'][0]['time_ms'])->toBeNumeric();
});

it('exposes the datalayer flag (true by default) in the payload config', function () {
    $response = $this->get('/__snip-test/html');

    preg_match('/<laravel-snip data-payload="([^"]*)"/', $response->getContent(), $match);
    $data = json_decode(html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'), true);

    expect($data['config']['datalayer'])->toBeTrue();
});

it('exposes the datalayer flag as false when disabled', function () {
    config()->set('snip.datalayer', false);

    $response = $this->get('/__snip-test/html');

    preg_match('/<laravel-snip data-payload="([^"]*)"/', $response->getContent(), $match);
    $data = json_decode(html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'), true);

    expect($data['config']['datalayer'])->toBeFalse();
});

it('forces private no-store cache headers when injecting', function () {
    $response = $this->get('/__snip-test/html');

    $cacheControl = $response->headers->get('Cache-Control');

    expect($cacheControl)->toContain('private')
        ->and($cacheControl)->toContain('no-store')
        ->and($response->headers->get('Pragma'))->toBe('no-cache')
        ->and($response->headers->get('Vary'))->toContain('Cookie');
});

it('does not touch cache headers when not injecting', function () {
    Gate::define('viewSnip', fn ($user = null) => false);

    $response = $this->get('/__snip-test/html');

    expect($response->headers->get('Cache-Control'))->not->toContain('no-store');
});

it('exposes the cache snapshot in the payload when keys are present', function () {
    config()->set('cache.default', 'array');
    Cache::store('array')->put('alpha', 'one', 60);
    Cache::store('array')->put('beta', 'two', 120);

    $response = $this->get('/__snip-test/html');

    preg_match('/<laravel-snip data-payload="([^"]*)"/', $response->getContent(), $match);
    $data = json_decode(html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'), true);

    expect($data)->toHaveKey('cache')
        ->and($data['cache']['driver'])->toBe('array')
        ->and($data['cache']['supported'])->toBeTrue()
        ->and(collect($data['cache']['keys'])->pluck('key')->all())
        ->toEqualCanonicalizing(['alpha', 'beta'])
        ->and($data['config']['cache'])->toBeTrue();
});

it('does not inject when only cache keys exist (manager captures required by default)', function () {
    config()->set('cache.default', 'array');
    Cache::store('array')->put('only-cache', 'value', 60);

    $response = $this->get('/__snip-test/no-snip');

    expect($response->getContent())->not->toContain('<laravel-snip');
});

it('injects on every gated response when display_mode = always', function () {
    config()->set('snip.display_mode', 'always');

    $response = $this->get('/__snip-test/no-snip');

    expect($response->getContent())->toContain('<laravel-snip data-payload=');
});

it('does not expose the cache section when disabled', function () {
    config()->set('snip.cache.enabled', false);
    config()->set('cache.default', 'array');
    Cache::store('array')->put('should-not-appear', 'x', 60);

    $response = $this->get('/__snip-test/html');

    preg_match('/<laravel-snip data-payload="([^"]*)"/', $response->getContent(), $match);
    $data = json_decode(html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'), true);

    expect($data)->not->toHaveKey('cache')
        ->and($data['config']['cache'])->toBeFalse();
});
