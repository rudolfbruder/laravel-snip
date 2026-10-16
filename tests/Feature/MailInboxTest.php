<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use RudolfBruder\LaravelSnip\Support\MailInbox;

beforeEach(function () {
    config()->set('mail.default', 'snip');
    Gate::define('viewSnip', fn ($user = null) => true);
    app(MailInbox::class)->clear();
});

it('adds a snip mailer, so MAIL_MAILER=snip is all the setup', function () {
    expect(config('mail.mailers.snip'))->toBe(['transport' => 'snip']);
});

it('catches mail sent from an artisan command', function () {
    Artisan::command('snip-test:remind', function (): void {
        Mail::raw('Your cart misses you.', fn ($message) => $message->to('jane@example.com')->subject('Reminder'));
    });

    $this->artisan('snip-test:remind')->assertSuccessful();

    $mails = $this->getJson('/_snip/mail')->assertOk()->json('mails');

    expect($mails)->toHaveCount(1)
        ->and($mails[0]['subject'])->toBe('Reminder')
        ->and($mails[0]['to'])->toBe(['jane@example.com'])
        ->and($mails[0]['sent_at'])->toBeString()
        ->and($mails[0]['origin'])->toBeString();

    $mail = $this->getJson('/_snip/mail/'.$mails[0]['id'])->assertOk()->json();

    expect($mail['text'])->toBe('Your cart misses you.')
        ->and($mail['mailer'])->toBe('snip');
});

it('names the request that sent the mail', function () {
    Route::post('/__snip-test/checkout', function () {
        Mail::raw('Thanks!', fn ($message) => $message->to('jane@example.com')->subject('Order placed'));

        return response('ok');
    });

    $this->post('/__snip-test/checkout')->assertOk();

    expect(app(MailInbox::class)->summaries()[0]['origin'])->toBe('POST /__snip-test/checkout');
});

it('catches mail from every mailer with snip.mail.catch, so it is never sent', function () {
    config()->set('mail.default', 'array');
    config()->set('snip.mail.catch', true);

    // Apps that pick a mailer per mail, like a shop sending through its own SMTP account.
    Mail::mailer('array')->raw('Hi', fn ($message) => $message->to('jane@example.com')->subject('Brand mail'));

    expect(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages())->toHaveCount(0)
        ->and(app(MailInbox::class)->summaries()[0]['subject'])->toBe('Brand mail')
        ->and(app(MailInbox::class)->enabled())->toBeTrue();
});

it('also shows caught mail among the request\'s own mail', function () {
    config()->set('snip.mail.enabled', true);

    Route::get('/__snip-test/caught-page', function () {
        Mail::raw('Hi', fn ($message) => $message->to('jane@example.com')->subject('Caught here'));

        return response('<html><body>page</body></html>')->header('Content-Type', 'text/html');
    });

    $data = snipPayloadFrom($this->get('/__snip-test/caught-page')->getContent());

    expect($data['mails'][0]['subject'])->toBe('Caught here');
});

it('leaves mail sent through other mailers out of the inbox', function () {
    Mail::mailer('array')->raw('Hi', fn ($message) => $message->to('jane@example.com'));

    expect(app(MailInbox::class)->summaries())->toBe([]);
});

it('keeps only the newest mails', function () {
    for ($i = 1; $i <= MailInbox::SIZE + 2; $i++) {
        Mail::raw('Hi', fn ($message) => $message->to('jane@example.com')->subject("Mail {$i}"));
    }

    $summaries = app(MailInbox::class)->summaries();

    expect($summaries)->toHaveCount(MailInbox::SIZE)
        ->and($summaries[0]['subject'])->toBe('Mail '.(MailInbox::SIZE + 2))
        ->and(end($summaries)['subject'])->toBe('Mail 3');
});

it('empties the inbox', function () {
    Mail::raw('Hi', fn ($message) => $message->to('jane@example.com'));
    $id = app(MailInbox::class)->summaries()[0]['id'];

    $this->deleteJson('/_snip/mail')->assertOk();

    expect(app(MailInbox::class)->summaries())->toBe([]);
    $this->getJson('/_snip/mail/'.$id)->assertNotFound();
});

it('points the panel at the inbox only when the app sends through the snip mailer', function () {
    Route::get('/__snip-test/inbox-page', function () {
        snip('x');

        return response('<html><body>page</body></html>')->header('Content-Type', 'text/html');
    });

    $config = fn (): array => snipPayloadFrom($this->get('/__snip-test/inbox-page')->getContent())['config'];

    expect($config()['mail_inbox_url'])->toBe('/_snip/mail');

    config()->set('mail.default', 'array');

    expect($config()['mail_inbox_url'])->toBeNull();
});

it('hides the inbox from users the gate denies', function () {
    Gate::define('viewSnip', fn ($user = null) => false);

    $this->getJson('/_snip/mail')->assertForbidden();
    $this->deleteJson('/_snip/mail')->assertForbidden();
});

it('answers 404 when the app does not send through the snip mailer', function () {
    config()->set('mail.default', 'array');

    $this->getJson('/_snip/mail')->assertNotFound();
});
