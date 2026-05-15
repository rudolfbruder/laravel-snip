<?php

declare(strict_types=1);

namespace RudolfBruder\LaravelSnip\Http\Controllers;

use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use RudolfBruder\LaravelSnip\Support\SnipDumper;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class CacheValueController extends Controller
{
    /**
     * Cap for the number of items returned when the resolved redis value is a
     * collection (set / list / hash / zset). Larger sets are sliced and a
     * `truncated` flag is set on the response.
     */
    private const COLLECTION_PREVIEW_LIMIT = 200;

    public function __construct(
        protected CacheFactory $cache,
        protected SnipDumper $dumper,
        protected ConfigRepository $config,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        if (! (bool) $this->config->get('snip.cache.enabled', true)) {
            throw new NotFoundHttpException;
        }

        if (! Gate::allows('viewSnip')) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        $key = (string) $request->query('key', '');
        if ($key === '') {
            return response()->json(['error' => 'missing_key'], 400);
        }

        try {
            $repository = $this->cache->store();
            $result = $this->fetch($repository, $key);
        } catch (Throwable $e) {
            return response()->json([
                'error' => 'lookup_failed',
                'message' => $e->getMessage(),
            ], 500);
        }

        if (! ($result['found'] ?? false)) {
            return response()->json([
                'key' => $key,
                'requested_key' => $key,
                'found' => false,
                'value' => null,
                'bytes' => null,
                'type' => null,
            ]);
        }

        return response()->json([
            'key' => $result['key'],
            'requested_key' => $key,
            'found' => true,
            'type' => $result['type'] ?? null,
            'truncated' => $result['truncated'] ?? false,
            'count' => $result['count'] ?? null,
            'value' => $this->dumper->dump($result['value']),
            'bytes' => $this->dumper->approximateSize($result['value']),
        ]);
    }

    /**
     * Resolve a value for the supplied key, branching on the underlying store.
     *
     * Redis stores need first-class type handling because Laravel's tagged
     * cache writes SET keys (`tag:<name>:entries`) and the queue / session
     * subsystems write LISTs / HASHes / ZSETs into the same DB. Calling
     * `Cache::get` on any of those triggers `WRONGTYPE Operation against a
     * key holding the wrong kind of value` because Cache::get issues a plain
     * GET. We probe the type via redis TYPE and use the right read command
     * for each kind.
     *
     * @return array{found: bool, key?: string, type?: string, value?: mixed, truncated?: bool, count?: ?int}
     */
    protected function fetch(Repository $repository, string $key): array
    {
        $store = $repository->getStore();

        if ($store instanceof RedisStore) {
            return $this->fetchFromRedis($store, $repository, $key);
        }

        foreach ($this->keyCandidates($key) as $candidate) {
            try {
                $value = $repository->get($candidate);
            } catch (Throwable) {
                continue;
            }

            if ($value !== null) {
                return ['found' => true, 'key' => $candidate, 'type' => 'value', 'value' => $value];
            }
        }

        return ['found' => false];
    }

    /**
     * @return array{found: bool, key?: string, type?: string, value?: mixed, truncated?: bool, count?: ?int}
     */
    protected function fetchFromRedis(RedisStore $store, Repository $repository, string $key): array
    {
        $connection = $store->connection();
        $storePrefix = method_exists($store, 'getPrefix') ? (string) $store->getPrefix() : '';
        $clientPrefix = $this->redisClientPrefix($connection);

        foreach ($this->redisKeyCandidates($key, $storePrefix, $clientPrefix) as $candidate) {
            $type = $this->detectType($connection, $candidate);

            if ($type === 'none' || $type === 'unknown') {
                continue;
            }

            return match ($type) {
                'string' => $this->readString($repository, $connection, $candidate),
                'set' => $this->readSet($connection, $candidate),
                'list' => $this->readList($connection, $candidate),
                'hash' => $this->readHash($connection, $candidate),
                'zset' => $this->readZset($connection, $candidate),
                default => [
                    'found' => true,
                    'key' => $candidate,
                    'type' => $type,
                    'value' => "(unsupported redis type: {$type})",
                ],
            };
        }

        return ['found' => false];
    }

    /**
     * Generate every plausible key form for redis lookup. Combines:
     *   - the key as received from the panel,
     *   - colon-split fallbacks (everything after first / last `:`),
     *   - variants with the cache-store prefix re-prepended,
     *   - variants with the redis client-level prefix stripped (covers
     *     phpredis OPT_PREFIX / predis options.prefix double-prefix
     *     scenarios — SCAN returns the prefixed key, TYPE/GET would
     *     re-prefix it again unless we strip it first).
     *
     * @return array<int, string>
     */
    protected function redisKeyCandidates(string $key, string $storePrefix, string $clientPrefix): array
    {
        $baseForms = $this->keyCandidates($key);
        $candidates = $baseForms;

        if ($clientPrefix !== '') {
            foreach ($baseForms as $form) {
                if (str_starts_with($form, $clientPrefix)) {
                    $candidates[] = substr($form, strlen($clientPrefix));
                }
            }
        }

        if ($storePrefix !== '') {
            foreach ($baseForms as $form) {
                $candidates[] = $storePrefix.$form;

                if (str_starts_with($form, $storePrefix)) {
                    $candidates[] = substr($form, strlen($storePrefix));
                }
            }

            if ($clientPrefix !== '') {
                foreach ($baseForms as $form) {
                    if (str_starts_with($form, $clientPrefix.$storePrefix)) {
                        $candidates[] = substr($form, strlen($clientPrefix.$storePrefix));
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($candidates, fn ($c) => $c !== '')));
    }

    /**
     * Best-effort read of the redis client's auto-prefix
     * (phpredis `Redis::OPT_PREFIX`, predis `options.prefix`).
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
     * @return array{found: bool, key: string, type: string, value: mixed}
     */
    protected function readString(Repository $repository, mixed $connection, string $key): array
    {
        // Prefer Cache::get on the SAME key shape that TYPE succeeded on — the
        // repository handles deserialization for native Laravel cache values.
        $value = null;

        try {
            $value = $repository->get($key);
        } catch (Throwable) {
            // fall through to raw read
        }

        if ($value === null) {
            try {
                $raw = $connection->get($key);
                $value = is_string($raw) ? $this->maybeUnserialize($raw) : $raw;
            } catch (Throwable) {
                $value = null;
            }
        }

        return ['found' => true, 'key' => $key, 'type' => 'string', 'value' => $value];
    }

    /**
     * @return array{found: bool, key: string, type: string, value: mixed, truncated: bool, count: int}
     */
    protected function readSet(mixed $connection, string $key): array
    {
        $count = (int) $connection->scard($key);
        $members = (array) $connection->smembers($key);

        return [
            'found' => true,
            'key' => $key,
            'type' => 'set',
            'value' => array_slice($members, 0, self::COLLECTION_PREVIEW_LIMIT),
            'truncated' => $count > self::COLLECTION_PREVIEW_LIMIT,
            'count' => $count,
        ];
    }

    /**
     * @return array{found: bool, key: string, type: string, value: mixed, truncated: bool, count: int}
     */
    protected function readList(mixed $connection, string $key): array
    {
        $count = (int) $connection->llen($key);
        $items = (array) $connection->lrange($key, 0, self::COLLECTION_PREVIEW_LIMIT - 1);

        return [
            'found' => true,
            'key' => $key,
            'type' => 'list',
            'value' => $items,
            'truncated' => $count > self::COLLECTION_PREVIEW_LIMIT,
            'count' => $count,
        ];
    }

    /**
     * @return array{found: bool, key: string, type: string, value: mixed, truncated: bool, count: int}
     */
    protected function readHash(mixed $connection, string $key): array
    {
        $count = (int) $connection->hlen($key);
        $all = (array) $connection->hgetall($key);

        return [
            'found' => true,
            'key' => $key,
            'type' => 'hash',
            'value' => array_slice($all, 0, self::COLLECTION_PREVIEW_LIMIT, true),
            'truncated' => $count > self::COLLECTION_PREVIEW_LIMIT,
            'count' => $count,
        ];
    }

    /**
     * @return array{found: bool, key: string, type: string, value: mixed, truncated: bool, count: int}
     */
    protected function readZset(mixed $connection, string $key): array
    {
        $count = (int) $connection->zcard($key);

        try {
            $items = (array) $connection->zrange($key, 0, self::COLLECTION_PREVIEW_LIMIT - 1, ['withscores' => true]);
        } catch (Throwable) {
            $items = (array) $connection->zrange($key, 0, self::COLLECTION_PREVIEW_LIMIT - 1);
        }

        return [
            'found' => true,
            'key' => $key,
            'type' => 'zset',
            'value' => $items,
            'truncated' => $count > self::COLLECTION_PREVIEW_LIMIT,
            'count' => $count,
        ];
    }

    protected function detectType(mixed $connection, string $key): string
    {
        try {
            $raw = $connection->type($key);
        } catch (Throwable) {
            return 'unknown';
        }

        $type = is_numeric($raw) ? $this->mapNumericType((int) $raw) : strtolower((string) $raw);

        return $type === '' ? 'none' : $type;
    }

    protected function mapNumericType(int $type): string
    {
        return match ($type) {
            0 => 'none',
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
     * Build the list of key forms to try. Mirrors `CacheSnapshot::keyCandidates`
     * intent (colon-split fallbacks) so a key shown in the panel resolves even
     * when the snapshot stripping left a namespace fragment attached.
     *
     * @return array<int, string>
     */
    protected function keyCandidates(string $key): array
    {
        $candidates = [$key];

        $firstColon = strpos($key, ':');
        if ($firstColon !== false) {
            $candidates[] = substr($key, $firstColon + 1);
        }

        $lastColon = strrpos($key, ':');
        if ($lastColon !== false && $lastColon !== $firstColon) {
            $candidates[] = substr($key, $lastColon + 1);
        }

        return array_values(array_unique(array_filter($candidates, fn ($c) => $c !== '')));
    }

    protected function maybeUnserialize(string $raw): mixed
    {
        $unserialized = @unserialize($raw);

        return $unserialized === false && $raw !== 'b:0;' ? $raw : $unserialized;
    }
}
