<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use RudolfBruder\LaravelSnip\Support\CapturingDecision;
use RudolfBruder\LaravelSnip\Support\GuestAccess;

/** @return array<string, mixed>|null */
function guestPayload(string $body): ?array
{
    if (! preg_match('/<laravel-snip data-payload="([^"]*)"/', $body, $match)) {
        return null;
    }

    return json_decode(html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'), true);
}

function denyGate(): void
{
    Gate::define('viewSnip', fn ($user = null) => false);
    app(CapturingDecision::class)->reset();
}

beforeEach(function () {
    config()->set('cache.default', 'array');
    Gate::define('viewSnip', fn ($user = null) => true);

    Route::middleware('web')->get('/__guest-test/page', function () {
        snip(['hello' => 'guest'], 'demo');

        return response('<html><body>page</body></html>')->header('Content-Type', 'text/html');
    });
});

it('lets a gated user create, read and revoke the guest link', function () {
    $created = $this->postJson('/_snip/guest-link')->assertOk()->json();

    expect($created['url'])->toStartWith('http://localhost/_snip/guest/')
        ->and($created['expires_at'])->toBeGreaterThan(time());

    expect($this->getJson('/_snip/guest-link')->json('url'))->toBe($created['url']);

    $this->deleteJson('/_snip/guest-link')->assertOk()->assertJson(['url' => null]);

    expect(app(GuestAccess::class)->current())->toBeNull();
});

it('replaces the previous link when a new one is created', function () {
    $first = app(GuestAccess::class)->create('tester');
    $second = app(GuestAccess::class)->create('tester');

    expect(app(GuestAccess::class)->accepts($first['token']))->toBeFalse()
        ->and(app(GuestAccess::class)->accepts($second['token']))->toBeTrue();
});

it('forbids managing links without the gate', function () {
    denyGate();

    $this->postJson('/_snip/guest-link')->assertForbidden();
    $this->getJson('/_snip/guest-link')->assertForbidden();
    $this->deleteJson('/_snip/guest-link')->assertForbidden();
});

it('sets the guest cookie and redirects home when a valid link is opened', function () {
    $link = app(GuestAccess::class)->create('tester');
    denyGate();

    $this->get('/_snip/guest/'.$link['token'])
        ->assertRedirect('/')
        ->assertCookie(GuestAccess::COOKIE, $link['token']);
});

it('answers 404 for unknown or revoked links', function () {
    $link = app(GuestAccess::class)->create('tester');
    app(GuestAccess::class)->revoke();

    $this->get('/_snip/guest/'.$link['token'])->assertNotFound();
    $this->get('/_snip/guest/not-a-token')->assertNotFound();
});

it('shows the panel to a guest carrying a valid cookie', function () {
    $link = app(GuestAccess::class)->create('tester');
    denyGate();

    $data = guestPayload($this->withCookie(GuestAccess::COOKIE, $link['token'])->get('/__guest-test/page')->getContent());

    expect($data)->not->toBeNull()
        ->and($data['snips'][0]['label'])->toBe('demo')
        ->and($data['config']['guest'])->toBeTrue()
        ->and($data['config']['guest_link_url'])->toBeNull();
});

it('hides the panel once the link is revoked', function () {
    $link = app(GuestAccess::class)->create('tester');
    app(GuestAccess::class)->revoke();
    denyGate();

    expect($this->withCookie(GuestAccess::COOKIE, $link['token'])->get('/__guest-test/page')->getContent())
        ->not->toContain('<laravel-snip');
});

it('does not let a guest manage links', function () {
    $link = app(GuestAccess::class)->create('tester');
    denyGate();

    $this->withCookie(GuestAccess::COOKIE, $link['token'])->postJson('/_snip/guest-link')->assertForbidden();
});

it('ignores guest cookies when guest links are switched off', function () {
    $link = app(GuestAccess::class)->create('tester');
    config()->set('snip.guest_links.enabled', false);
    denyGate();

    expect($this->withCookie(GuestAccess::COOKIE, $link['token'])->get('/__guest-test/page')->getContent())
        ->not->toContain('<laravel-snip');
    $this->get('/_snip/guest/'.$link['token'])->assertNotFound();
});

it('offers link management to gated users only', function () {
    $data = guestPayload($this->get('/__guest-test/page')->getContent());

    expect($data['config']['guest_link_url'])->toBe('/_snip/guest-link')
        ->and($data['config']['guest'])->toBeFalse();
});

it('records who created the link, and logs create, open and revoke without the token', function () {
    Log::spy();
    $admin = (new User)->forceFill(['id' => 7, 'email' => 'admin@example.com']);

    $created = $this->actingAs($admin)->postJson('/_snip/guest-link')->json();
    $token = basename($created['url']);
    $fingerprint = app(GuestAccess::class)->fingerprint($token);

    expect($created['created_by'])->toBe('admin@example.com')
        ->and($created['created_at'])->toBeLessThanOrEqual(time());

    $this->get($created['url']);
    $this->actingAs($admin)->deleteJson('/_snip/guest-link');

    $logged = fn (string $message, array $expected) => Log::shouldHaveReceived('info')->withArgs(
        fn (string $m, array $context) => $m === $message
            && array_intersect_assoc($expected, $context) === $expected
            && ! str_contains(json_encode($context), $token),
    )->once();

    $logged('[laravel-snip] guest link created', ['link' => $fingerprint, 'ip' => '127.0.0.1']);
    $logged('[laravel-snip] guest link opened', ['link' => $fingerprint, 'created_by' => 'admin@example.com']);
    $logged('[laravel-snip] guest link revoked', ['link' => $fingerprint, 'revoked_by' => 'admin@example.com', 'created_by' => 'admin@example.com']);
});
