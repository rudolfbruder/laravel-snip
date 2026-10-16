<?php

declare(strict_types=1);

namespace RudolfBruder\LaravelSnip\Support;

use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Str;

/**
 * Mail caught instead of sent, kept in the application cache so every process can add to it
 * and the panel can read it: a request, an artisan command and a queue worker all share one inbox.
 *
 * Each mail lives under its own key and the index holds a short summary of the newest ones, so the
 * panel lists the inbox without loading every body. Old mail falls off the end of the index and
 * expires with the cache TTL.
 */
class MailInbox
{
    public const INDEX_KEY = 'snip:mail-inbox';

    public const MAIL_KEY_PREFIX = 'snip:mail-inbox:';

    /** Mails kept; older ones are dropped as new ones arrive. */
    public const SIZE = 50;

    /** Seconds a caught mail is kept. */
    public const TTL = 86400;

    public function __construct(
        protected CacheFactory $cache,
        protected ConfigRepository $config,
    ) {}

    /** Whether the app's mail is caught, so the panel shows the inbox. */
    public function enabled(): bool
    {
        return $this->catches((string) $this->config->get('mail.default'));
    }

    /**
     * Whether mail sent through the named mailer is caught: every mailer's with `snip.mail.catch` on,
     * else only a mailer using the `snip` transport (`MAIL_MAILER=snip`). Apps that pick a mailer per
     * mail, such as `Mail::mailer('shop_sk')`, need the former.
     */
    public function catches(?string $mailer): bool
    {
        if (! $this->config->get('snip.enabled', true)) {
            return false;
        }

        if ($this->config->get('snip.mail.catch', false)) {
            return true;
        }

        return $mailer !== null
            && $mailer !== ''
            && $this->config->get("mail.mailers.{$mailer}.transport") === SnipMailTransport::NAME;
    }

    /** @param  array<string, mixed>  $mail  As built by MailListener. */
    public function push(array $mail): string
    {
        $id = (string) Str::ulid();
        $store = $this->store();

        $store->put(self::MAIL_KEY_PREFIX.$id, ['id' => $id] + $mail, self::TTL);

        $this->locked(function () use ($store, $id, $mail): void {
            $index = $this->summaries();
            array_unshift($index, $this->summary($id, $mail));

            foreach (array_slice($index, self::SIZE) as $dropped) {
                $store->forget(self::MAIL_KEY_PREFIX.$dropped['id']);
            }

            $store->put(self::INDEX_KEY, array_slice($index, 0, self::SIZE), self::TTL);
        });

        return $id;
    }

    /** @return list<array<string, mixed>> Newest first. */
    public function summaries(): array
    {
        $index = $this->store()->get(self::INDEX_KEY);

        return is_array($index) ? array_values($index) : [];
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        $mail = $this->store()->get(self::MAIL_KEY_PREFIX.$id);

        return is_array($mail) ? $mail : null;
    }

    public function clear(): void
    {
        $this->locked(function (): void {
            foreach ($this->summaries() as $summary) {
                $this->store()->forget(self::MAIL_KEY_PREFIX.$summary['id']);
            }

            $this->store()->forget(self::INDEX_KEY);
        });
    }

    /**
     * @param  array<string, mixed>  $mail
     * @return array<string, mixed>
     */
    protected function summary(string $id, array $mail): array
    {
        return [
            'id' => $id,
            'subject' => $mail['subject'] ?? null,
            'to' => array_merge($mail['to'] ?? [], $mail['cc'] ?? [], $mail['bcc'] ?? []),
            'sent_at' => $mail['sent_at'] ?? null,
            'origin' => $mail['origin'] ?? null,
            'attachments' => count($mail['attachments'] ?? []),
        ];
    }

    /**
     * Two processes sending at once would otherwise both read the index and the later write would
     * drop the other's mail. Stores without locks, or a lock that cannot be had in time, still write.
     */
    protected function locked(Closure $callback): void
    {
        $store = $this->store()->getStore();

        if (! $store instanceof LockProvider) {
            $callback();

            return;
        }

        try {
            $store->lock(self::INDEX_KEY.':lock', 10)->block(5, $callback);
        } catch (LockTimeoutException) {
            $callback();
        }
    }

    protected function store(): CacheRepository
    {
        return $this->cache->store();
    }
}
