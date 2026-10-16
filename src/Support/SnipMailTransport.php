<?php

declare(strict_types=1);

namespace RudolfBruder\LaravelSnip\Support;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * The `snip` mail transport: a middleman that delivers nothing. Mail sent through a mailer that uses
 * it is kept in the MailInbox by MailListener instead, from requests, artisan commands and queue
 * workers alike, and read in the panel's Mail tab.
 */
class SnipMailTransport extends AbstractTransport
{
    public const NAME = 'snip';

    protected function doSend(SentMessage $message): void
    {
        // Intentionally empty: catching the mail is the whole point.
    }

    public function __toString(): string
    {
        return self::NAME.'://';
    }
}
