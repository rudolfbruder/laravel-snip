<?php

declare(strict_types=1);

namespace RudolfBruder\LaravelSnip\Support;

use Closure;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\MemcachedStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Throwable;

class CacheSnapshot
{
    public function __construct(
        protected ConfigRepository $config,
        protected CacheFactory $cache,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->config->get('snip.cache.enabled', true);
    }

    /**
     * Build a JSON-safe snapshot of the active cache store.
     *
     * @return array{
     *     driver: string,
     *     prefix: string,
     *     supported: bool,
     *     keys: array<int, array{key: string, ttl: ?int, bytes: ?int, hashed?: bool}>,
     *     truncated: bool,
     *     message: ?string,
     * }
     */
    public function capture(): array
    {
        $driver = (string) $this->config->get('cache.default', 'array');
        $max = max(1, (int) $this->config->get('snip.cache.max_keys', 500));

        $base = [
            'driver' => $driver,
            'prefix' => '',
            'supported' => false,
            'keys' => [],
            'truncated' => false,
            'message' => null,
        ];

        try {
            $repository = $this->cache->store();
            $store = $repository->getStore();
            $base['prefix'] = $this->resolvePrefix($store);

            return match (true) {
                $store instanceof RedisStore => $this->fromRedis($store, $base, $max),
                $store instanceof ArrayStore => $this->fromArray($store, $base, $max),
                $store instanceof DatabaseStore => $this->fromDatabase($store, $base, $max),
                $store instanceof FileStore => $this->fromFile($store, $base, $max),
                $store instanceof MemcachedStore => $this->unsupported($base, 'Memcached does not support reliable key enumeration.'),
                default => $this->unsupported($base, 'Driver "'.$driver.'" not supported.'),
            };
        } catch (Throwable $e) {
            $base['message'] = 'Failed to read cache: '.$e->getMessage();

            return $base;
        }
    }

    /**
     * @param  array{driver: string, prefix: string, supported: bool, keys: array, truncated: bool, message: ?string}  $base
     */
    protected function fromRedis(RedisStore $store, array $base, int $max): array
    {
        $connection = $store->connection();
        $prefix = $base['prefix'];
        $clientPrefix = $this->redisClientPrefix($connection);
        $matchPrefix = (bool) $this->config->get('snip.cache.match_prefix', true);
        $pattern = ($matchPrefix && $prefix !== '') ? $prefix.'*' : '*';

        $keys = [];
        $cursor = '0';
        $truncated = false;

        do {
            $result = $connection->scan($cursor, ['match' => $pattern, 'count' => 200]);

            // phpredis returns [cursor, [keys]]; predis returns [cursor, [keys]] too,
            // but Laravel's wrapper may also return false when iteration ends.
            if ($result === false || $result === null) {
                break;
            }

            [$cursor, $batch] = is_array($result) && array_keys($result) === [0, 1]
                ? $result
                : ['0', is_array($result) ? $result : []];

            foreach ((array) $batch as $rawKey) {
                $rawKey = (string) $rawKey;
                $bareKey = $prefix !== '' && str_starts_with($rawKey, $prefix)
                    ? substr($rawKey, strlen($prefix))
                    : $rawKey;

                $ttl = $this->redisTtl($connection, $rawKey, $bareKey, $prefix, $clientPrefix);
                $bytes = $this->redisValueBytes($connection, $rawKey, $bareKey, $prefix, $clientPrefix);

                $keys[] = [
                    'key' => $bareKey,
                    'ttl' => $ttl,
                    'bytes' => $bytes,
                ];

                if (count($keys) >= $max) {
                    $truncated = true;
                    break 2;
                }
            }
        } while ((string) $cursor !== '0');

        $base['supported'] = true;
        $base['keys'] = $keys;
        $base['truncated'] = $truncated;

        return $base;
    }

    /**
     * Resolve TTL for a redis key. Different client/config combos return keys
     * from SCAN in different forms: phpredis with OPT_PREFIX strips the
     * connection-level prefix on return, predis with options.prefix does the
     * same, and plain clients return everything as-is. The matching TTL call
     * may or may not auto-prefix on its way out, which means a single guess
     * about which form to send is unreliable — try all plausible forms and
     * keep the first one that returns a value other than -2 ("no key").
     */
    protected function redisTtl(mixed $connection, string $rawKey, string $bareKey, string $prefix, string $clientPrefix): ?int
    {
        foreach ($this->keyCandidates($rawKey, $bareKey, $prefix, $clientPrefix) as $candidate) {
            $ttl = $this->safeInt(fn () => (int) $connection->ttl($candidate));

            if ($ttl === null || $ttl === -2) {
                continue;
            }

            // -1 = key exists without expiry → "forever".
            return $ttl < 0 ? null : $ttl;
        }

        return null;
    }

    /**
     * Build every plausible form of a cache key the underlying client might
     * accept. Redis client/SCAN interaction varies across phpredis/predis
     * versions: SCAN may return keys with the client-level prefix (`OPT_PREFIX`
     * / `options.prefix`) still attached and TTL may auto-prepend that prefix
     * again, producing -2. Stripping the client prefix first — then
     * combining with the cache-store prefix — covers all combinations:
     *
     *   - rawKey               (as returned by SCAN, unchanged)
     *   - bareKey              (rawKey - cache prefix)
     *   - prefix+rawKey        (rawKey with cache prefix re-prepended)
     *   - prefix+bareKey       (bareKey with cache prefix re-prepended)
     *   - rawKey - clientPrefix
     *   - bareKey - clientPrefix
     *   - (rawKey - clientPrefix) - cache prefix
     *
     * @return array<int, string>
     */
    protected function keyCandidates(string $rawKey, string $bareKey, string $prefix, string $clientPrefix = ''): array
    {
        $candidates = [$rawKey, $bareKey];

        if ($prefix !== '') {
            $candidates[] = $prefix.$rawKey;
            $candidates[] = $prefix.$bareKey;
        }

        if ($clientPrefix !== '') {
            $stripRaw = str_starts_with($rawKey, $clientPrefix)
                ? substr($rawKey, strlen($clientPrefix))
                : $rawKey;
            $stripBare = str_starts_with($bareKey, $clientPrefix)
                ? substr($bareKey, strlen($clientPrefix))
                : $bareKey;

            $candidates[] = $stripRaw;
            $candidates[] = $stripBare;

            if ($prefix !== '' && str_starts_with($stripRaw, $prefix)) {
                $candidates[] = substr($stripRaw, strlen($prefix));
            }
        }

        return array_values(array_unique(array_filter($candidates, fn ($c) => $c !== '')));
    }

    /**
     * Best-effort detection of the redis client's auto-prefix (phpredis
     * `OPT_PREFIX` / predis `options.prefix`). Returns the empty string when
     * no prefix is configured or the client API differs from what we know.
     */
    protected function redisClientPrefix(mixed $connection): string
    {
        try {
            $client = method_exists($connection, 'client') ? $connection->client() : $connection;

            if (is_object($client) && method_exists($client, 'getOption') && defined('\Redis::OPT_PREFIX')) {
                $value = $client->getOption(\Redis::OPT_PREFIX);
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }

            if (is_object($client) && method_exists($client, 'getOptions')) {
                $options = $client->getOptions();
                $prefix = $options->prefix ?? null;
                if ($prefix !== null) {
                    $value = (string) $prefix;
                    if ($value !== '') {
                        return $value;
                    }
                }
            }
        } catch (Throwable) {
            // fall through
        }

        return '';
    }

    /**
     * Approximate byte size of a redis value. For strings, use STRLEN (content
     * bytes only). For other types, use MEMORY USAGE (total bytes including
     * overhead — best universal metric across list/set/hash/zset/stream).
     * Returns null when both probes fail (e.g. redis < 4.0 with non-string
     * type, or auth lacks MEMORY command).
     */
    protected function redisValueBytes(mixed $connection, string $rawKey, string $bareKey, string $prefix, string $clientPrefix = ''): ?int
    {
        $candidates = $this->keyCandidates($rawKey, $bareKey, $prefix, $clientPrefix);
        $type = $this->redisType($connection, $candidates);

        if ($type === 'string') {
            foreach ($candidates as $candidate) {
                $bytes = $this->safeInt(fn () => (int) $connection->strlen($candidate));
                if ($bytes !== null && $bytes > 0) {
                    return $bytes;
                }
            }

            return null;
        }

        foreach ($candidates as $candidate) {
            $bytes = $this->safeInt(fn () => (int) $connection->command('MEMORY', ['USAGE', $candidate]));
            if ($bytes !== null && $bytes > 0) {
                return $bytes;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $candidates
     */
    protected function redisType(mixed $connection, array $candidates): string
    {
        foreach ($candidates as $candidate) {
            try {
                $type = (string) $connection->type($candidate);
            } catch (Throwable) {
                continue;
            }

            if ($type === '' || $type === 'none' || $type === '0') {
                continue;
            }

            return is_numeric($type) ? $this->redisTypeName((int) $type) : strtolower($type);
        }

        return 'unknown';
    }

    protected function redisTypeName(int $type): string
    {
        return match ($type) {
            1 => 'string',
            2 => 'set',
            3 => 'list',
            4 => 'zset',
            5 => 'hash',
            6 => 'stream',
            default => 'unknown',
        };
    }

    /**
     * @param  array{driver: string, prefix: string, supported: bool, keys: array, truncated: bool, message: ?string}  $base
     */
    protected function fromArray(ArrayStore $store, array $base, int $max): array
    {
        $storage = Closure::bind(fn () => $this->storage, $store, ArrayStore::class)();

        $keys = [];
        $truncated = false;

        foreach ((array) $storage as $key => $payload) {
            $value = is_array($payload) ? ($payload['value'] ?? null) : $payload;
            $expiresAt = is_array($payload) ? ($payload['expiresAt'] ?? 0) : 0;

            $ttl = null;
            if (is_numeric($expiresAt) && $expiresAt > 0) {
                $ttl = max(0, (int) round($expiresAt - microtime(true)));
            }

            $keys[] = [
                'key' => (string) $key,
                'ttl' => $ttl,
                'bytes' => $this->safeInt(fn () => strlen(serialize($value))),
            ];

            if (count($keys) >= $max) {
                $truncated = true;
                break;
            }
        }

        $base['supported'] = true;
        $base['keys'] = $keys;
        $base['truncated'] = $truncated;

        return $base;
    }

    /**
     * @param  array{driver: string, prefix: string, supported: bool, keys: array, truncated: bool, message: ?string}  $base
     */
    protected function fromDatabase(DatabaseStore $store, array $base, int $max): array
    {
        $table = (string) $this->config->get('cache.stores.database.table', 'cache');
        $connection = (string) $this->config->get('cache.stores.database.connection') ?: null;

        $rows = DB::connection($connection)->table($table)
            ->select(['key', 'expiration'])
            ->selectRaw('LENGTH(value) as bytes')
            ->limit($max + 1)
            ->get();

        $prefix = $base['prefix'];
        $keys = [];
        $truncated = false;

        foreach ($rows as $row) {
            if (count($keys) >= $max) {
                $truncated = true;
                break;
            }

            $key = (string) $row->key;
            $bare = $prefix !== '' && str_starts_with($key, $prefix)
                ? substr($key, strlen($prefix))
                : $key;

            $ttl = null;
            if (isset($row->expiration) && is_numeric($row->expiration)) {
                $ttl = max(0, (int) $row->expiration - time());
            }

            $keys[] = [
                'key' => $bare,
                'ttl' => $ttl,
                'bytes' => isset($row->bytes) && is_numeric($row->bytes) ? (int) $row->bytes : null,
            ];
        }

        $base['supported'] = true;
        $base['keys'] = $keys;
        $base['truncated'] = $truncated;

        return $base;
    }

    /**
     * File driver hashes keys with SHA1 — originals cannot be recovered.
     * Surface hashes + file size so the user at least sees how many entries
     * the cache holds and which ones are heavy.
     *
     * @param  array{driver: string, prefix: string, supported: bool, keys: array, truncated: bool, message: ?string}  $base
     */
    protected function fromFile(FileStore $store, array $base, int $max): array
    {
        $files = new Filesystem;
        $directory = (string) $store->getDirectory();

        if (! $files->isDirectory($directory)) {
            return $this->unsupported($base, 'Cache directory does not exist.');
        }

        $keys = [];
        $truncated = false;

        foreach ($files->allFiles($directory) as $file) {
            if (count($keys) >= $max) {
                $truncated = true;
                break;
            }

            $hash = $file->getFilename();

            $ttl = $this->safeInt(function () use ($file) {
                $contents = @file_get_contents($file->getPathname(), false, null, 0, 10);

                return $contents === false ? null : max(0, (int) $contents - time());
            });

            $keys[] = [
                'key' => $hash,
                'ttl' => $ttl,
                'bytes' => $this->safeInt(fn () => (int) $file->getSize()),
                'hashed' => true,
            ];
        }

        $base['supported'] = true;
        $base['keys'] = $keys;
        $base['truncated'] = $truncated;
        $base['message'] = 'File driver hashes keys with SHA1 — original key names are not recoverable.';

        return $base;
    }

    /**
     * @param  array{driver: string, prefix: string, supported: bool, keys: array, truncated: bool, message: ?string}  $base
     */
    protected function unsupported(array $base, string $message): array
    {
        $base['supported'] = false;
        $base['message'] = $message;

        return $base;
    }

    protected function safeInt(Closure $producer): ?int
    {
        try {
            $value = $producer();

            return $value === null ? null : (int) $value;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Resolve the effective key prefix used for SCAN pattern building and for
     * stripping from displayed keys.
     *
     *   Override (snip.cache.prefix) > auto-detected store prefix > ''
     *
     * Laravel's default `cache.prefix` appends a literal `_cache_` segment
     * (`${APP_NAME}_cache_`) which rarely appears in the real on-the-wire
     * key — most apps either set their own clean prefix via the redis
     * connection's `options.prefix` or wipe `cache.prefix` entirely. When
     * the user has not provided an override we strip that trailing
     * `_cache_` / `cache_` / `:cache:` / `cache:` segment so the panel
     * matches what `redis-cli KEYS '*'` actually shows. Override path is
     * never massaged — the value the user typed wins exactly.
     */
    protected function resolvePrefix(mixed $store): string
    {
        $override = $this->config->get('snip.cache.prefix');
        if (is_string($override) && $override !== '') {
            return $override;
        }

        $detected = method_exists($store, 'getPrefix') ? (string) $store->getPrefix() : '';

        return $this->stripCacheSegment($detected);
    }

    protected function stripCacheSegment(string $prefix): string
    {
        $stripped = preg_replace('/[_:]cache[_:]?$/i', '', $prefix);

        return is_string($stripped) ? $stripped : $prefix;
    }
}
