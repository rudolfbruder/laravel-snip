<?php

declare(strict_types=1);

namespace RudolfBruder\LaravelSnip\Support;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Mail\Events\MessageSent;
use RudolfBruder\LaravelSnip\SnipManager;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Records every mail the request sends for the panel's Mail tab: the envelope, the HTML and text
 * bodies, and the attachments by name and size. Only reads the message; delivery is left alone.
 *
 * Mail queued for a worker is recorded only when the sync driver sends it inside the request.
 */
class MailListener
{
    /** Bodies longer than this (in bytes) are cut, so one newsletter cannot bloat the payload. */
    private const MAX_BODY_BYTES = 512 * 1024;

    /** Inline images up to this size are put into the HTML preview as data URIs. */
    private const MAX_INLINE_IMAGE_BYTES = 256 * 1024;

    public function __construct(
        protected Container $app,
        protected Dispatcher $events,
    ) {}

    public function register(): void
    {
        $this->events->listen(MessageSent::class, function (MessageSent $event): void {
            $manager = $this->app->make(SnipManager::class);

            // Checked before reading the message, so the body is never copied for users who cannot see it.
            if (! $manager->canRecordMail()) {
                return;
            }

            $manager->recordMail($this->describe($event->message, $event->data));
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function describe(Email $message, array $data): array
    {
        $html = $this->body($message->getHtmlBody());
        $text = $this->body($message->getTextBody());
        $attachments = $message->getAttachments();

        return [
            'subject' => $message->getSubject(),
            'from' => $this->addresses($message->getFrom()),
            'to' => $this->addresses($message->getTo()),
            'cc' => $this->addresses($message->getCc()),
            'bcc' => $this->addresses($message->getBcc()),
            'reply_to' => $this->addresses($message->getReplyTo()),
            'mailer' => is_string($data['mailer'] ?? null) ? $data['mailer'] : null,
            'source' => $this->source($data),
            'html' => $html === null ? null : $this->truncate($this->inlineImages($html, $attachments)),
            'text' => $text === null ? null : $this->truncate($text),
            'attachments' => array_map(fn (DataPart $part): array => [
                'name' => $part->getFilename(),
                'content_type' => $part->getMediaType().'/'.$part->getMediaSubtype(),
                'bytes' => strlen($part->getBody()),
                // Embedding gives a part its Content-ID; plain attachments have none.
                'inline' => $part->hasContentId(),
            ], $attachments),
        ];
    }

    /** The Mailable or Notification class that built the mail, when it was one. */
    protected function source(array $data): ?string
    {
        foreach (['__laravel_mailable', '__laravel_notification'] as $key) {
            if (is_string($data[$key] ?? null)) {
                return $data[$key];
            }
        }

        return null;
    }

    /**
     * @param  list<Address>  $addresses
     * @return list<string>
     */
    protected function addresses(array $addresses): array
    {
        return array_map(fn (Address $address): string => $address->toString(), $addresses);
    }

    /** @param  resource|string|null  $body */
    protected function body(mixed $body): ?string
    {
        if (is_resource($body)) {
            rewind($body);
            $body = stream_get_contents($body);
        }

        return is_string($body) ? $body : null;
    }

    /**
     * Embedded images are referenced as `cid:` URLs, which a browser cannot load; swapping in their
     * data keeps logos and pictures in the preview.
     *
     * @param  list<DataPart>  $attachments
     */
    protected function inlineImages(string $html, array $attachments): string
    {
        foreach ($attachments as $part) {
            if (! $part->hasContentId() || $part->getMediaType() !== 'image') {
                continue;
            }

            $body = $part->getBody();

            if (strlen($body) > self::MAX_INLINE_IMAGE_BYTES) {
                continue;
            }

            $html = str_replace(
                'cid:'.$part->getContentId(),
                'data:image/'.$part->getMediaSubtype().';base64,'.base64_encode($body),
                $html,
            );
        }

        return $html;
    }

    protected function truncate(string $body): string
    {
        if (strlen($body) <= self::MAX_BODY_BYTES) {
            return $body;
        }

        return mb_strcut($body, 0, self::MAX_BODY_BYTES)."\n\n[laravel-snip: cut at ".(self::MAX_BODY_BYTES / 1024).' KB]';
    }
}
