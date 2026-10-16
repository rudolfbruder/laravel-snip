<?php

declare(strict_types=1);

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;

class SnipTestWelcomeMail extends Mailable
{
    public function build(): self
    {
        return $this->subject('Welcome aboard')
            ->html('<h1>Hello</h1>')
            ->attachData('pdf-bytes', 'invoice.pdf', ['mime' => 'application/pdf']);
    }
}

beforeEach(function () {
    config()->set('mail.default', 'array');
    Gate::define('viewSnip', fn ($user = null) => true);

    Route::get('/__snip-test/mail', function () {
        Mail::to(new Address('jane@example.com', 'Jane Doe'))->cc('boss@example.com')->send(new SnipTestWelcomeMail());

        return response('<html><body>sent</body></html>')->header('Content-Type', 'text/html');
    });
});

it('is off by default', function () {
    $response = $this->get('/__snip-test/mail');

    expect(config('snip.mail.enabled'))->toBeFalse()
        ->and($response->getContent())->not->toContain('<laravel-snip');
});

function enableSnipMail(): void
{
    config()->set('snip.mail.enabled', true);
}

it('shows the mail a request sends, which alone is enough to inject the panel', function () {
    enableSnipMail();

    $data = snipPayloadFrom($this->get('/__snip-test/mail')->getContent());

    expect($data['config']['mail'])->toBeTrue()
        ->and($data['mails'])->toHaveCount(1);

    $mail = $data['mails'][0];

    expect($mail['subject'])->toBe('Welcome aboard')
        ->and($mail['to'])->toBe(['"Jane Doe" <jane@example.com>'])
        ->and($mail['cc'])->toBe(['boss@example.com'])
        ->and($mail['mailer'])->toBe('array')
        ->and($mail['source'])->toBe(SnipTestWelcomeMail::class)
        ->and($mail['html'])->toBe('<h1>Hello</h1>')
        ->and($mail['attachments'])->toBe([
            ['name' => 'invoice.pdf', 'content_type' => 'application/pdf', 'bytes' => 9, 'inline' => false],
        ])
        ->and($mail['time_ms'])->toBeFloat();
});

it('still delivers the mail', function () {
    enableSnipMail();
    $this->get('/__snip-test/mail');

    expect(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages())->toHaveCount(1);
});

it('puts embedded images into the HTML preview', function () {
    enableSnipMail();
    Route::get('/__snip-test/mail-embed', function () {
        Mail::send([], [], function (Message $message): void {
            $cid = $message->embedData('png-bytes', 'logo.png', 'image/png');
            $message->to('jane@example.com')->subject('Logo')->html('<img src="'.$cid.'">');
        });

        return response('<html><body>sent</body></html>')->header('Content-Type', 'text/html');
    });

    $mail = snipPayloadFrom($this->get('/__snip-test/mail-embed')->getContent())['mails'][0];

    expect($mail['html'])->toBe('<img src="data:image/png;base64,'.base64_encode('png-bytes').'">')
        ->and($mail['attachments'][0]['inline'])->toBeTrue();
});

it('carries mail sent before a redirect to the next page', function () {
    enableSnipMail();
    Route::middleware('web')->get('/__snip-test/mail-redirect', function () {
        Mail::to('jane@example.com')->send(new SnipTestWelcomeMail());

        return redirect('/__snip-test/mail-landing');
    });

    Route::middleware('web')->get('/__snip-test/mail-landing', fn () => response('<html><body>landed</body></html>')
        ->header('Content-Type', 'text/html'));

    $this->get('/__snip-test/mail-redirect')->assertRedirect();
    $data = snipPayloadFrom($this->get('/__snip-test/mail-landing')->getContent());

    expect($data['mails'][0]['subject'])->toBe('Welcome aboard');
});

it('does not record mail for users the gate denies', function () {
    enableSnipMail();
    Gate::define('viewSnip', fn ($user = null) => false);

    expect($this->get('/__snip-test/mail')->getContent())->not->toContain('<laravel-snip');
});

it('caps the mail recorded per request', function () {
    enableSnipMail();
    config()->set('snip.limits.max_mails_per_request', 1);

    Route::get('/__snip-test/mail-twice', function () {
        Mail::to('a@example.com')->send(new SnipTestWelcomeMail());
        Mail::to('b@example.com')->send(new SnipTestWelcomeMail());

        return response('<html><body>sent</body></html>')->header('Content-Type', 'text/html');
    });

    expect(snipPayloadFrom($this->get('/__snip-test/mail-twice')->getContent())['mails'])->toHaveCount(1);
});
