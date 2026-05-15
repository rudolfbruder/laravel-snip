<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    config()->set('cache.default', 'array');
});

it('returns the dumped value when key exists and gate allows', function () {
    Gate::define('viewSnip', fn ($user = null) => true);
    Cache::store('array')->put('hello.world', ['greeting' => 'hi', 'count' => 3], 60);

    $response = $this->getJson('/_snip/cache?key=hello.world');

    $response->assertOk();

    $data = $response->json();

    expect($data['found'])->toBeTrue()
        ->and($data['key'])->toBe('hello.world')
        ->and($data['value'])->toBeArray()
        ->and($data['bytes'])->toBeInt()
        ->and($data['bytes'])->toBeGreaterThan(0);
});

it('returns found=false when key does not exist', function () {
    Gate::define('viewSnip', fn ($user = null) => true);

    $response = $this->getJson('/_snip/cache?key=no.such.key');

    $response->assertOk();

    expect($response->json('found'))->toBeFalse();
});

it('returns 403 when gate denies', function () {
    Gate::define('viewSnip', fn ($user = null) => false);
    Cache::store('array')->put('secret', 'shh', 60);

    $response = $this->getJson('/_snip/cache?key=secret');

    $response->assertStatus(403);
});

it('returns 400 when key is missing', function () {
    Gate::define('viewSnip', fn ($user = null) => true);

    $response = $this->getJson('/_snip/cache');

    $response->assertStatus(400);
});

it('returns 200 with found=false when array store has no such tag-like key (no 500)', function () {
    Gate::define('viewSnip', fn ($user = null) => true);

    $response = $this->getJson('/_snip/cache?key=tag:App\\\\Domain:entries');

    $response->assertOk();
    expect($response->json('found'))->toBeFalse();
});

it('falls back to the segment after the first colon when the raw key is not stored', function () {
    Gate::define('viewSnip', fn ($user = null) => true);
    Cache::store('array')->put('users.1', ['id' => 1, 'name' => 'Ada'], 60);

    // Panel sends `cache:users.1`; the colon-delimited prefix is not stored on
    // the cache, only `users.1` is. The controller must fall back to the
    // segment after the first colon.
    $response = $this->getJson('/_snip/cache?key=cache:users.1');

    $response->assertOk();

    expect($response->json('found'))->toBeTrue()
        ->and($response->json('key'))->toBe('users.1')
        ->and($response->json('requested_key'))->toBe('cache:users.1');
});

it('returns 404 when cache snapshot is disabled', function () {
    config()->set('snip.cache.enabled', false);
    Gate::define('viewSnip', fn ($user = null) => true);

    $response = $this->getJson('/_snip/cache?key=anything');

    $response->assertNotFound();
});
