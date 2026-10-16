<?php

declare(strict_types=1);

namespace RudolfBruder\LaravelSnip\Support;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * The `snip` mail transport, behind `MAIL_MAILER=snip`: delivers nothing. MailListener stops mail sent
 * through it before it gets here and keeps it in the MailInbox; the transport only makes sure that
 * nothing leaves if the listener is not registered (the package switched off).
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
