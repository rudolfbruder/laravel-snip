<?php

declare(strict_types=1);

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

it('emits a JSON-decodable payload via the data attribute', function () {
    $response = $this->get('/__snip-test/html');

    $body = $response->getContent();

    preg_match('/<laravel-snip data-payload="([^"]*)"/', $body, $match);
    expect($match[1] ?? null)->not->toBeNull();

    $decoded = html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
    $data = json_decode($decoded, true);

    expect($data)->toBeArray()
        ->and($data[0]['label'])->toBe('demo')
        ->and($data[0]['value']['children'][0]['key'])->toBe('hello');
});
