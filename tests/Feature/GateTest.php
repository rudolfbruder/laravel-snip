<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;

it('default gate allows in local environment', function () {
    app()['env'] = 'local';
    expect(Gate::allows('viewSnip'))->toBeTrue();
});

it('default gate denies outside local', function () {
    app()['env'] = 'production';
    expect(Gate::allows('viewSnip'))->toBeFalse();
});

it('asset endpoint returns 404 when gate denies', function () {
    Gate::define('viewSnip', fn ($user = null) => false);

    $response = $this->get(config('snip.asset_route'));

    $response->assertNotFound();
});

it('asset endpoint serves js when gate allows', function () {
    Gate::define('viewSnip', fn ($user = null) => true);

    $response = $this->get(config('snip.asset_route'));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('application/javascript');
});
