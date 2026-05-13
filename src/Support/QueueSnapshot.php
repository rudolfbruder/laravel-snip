<?php

declare(strict_types=1);

namespace RudolfBruder\LaravelSnip\Support;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Read-only snapshot of jobs across the configured queue driver.
 *
 * Surfaces four lists:
 *   - failed     → from `queue.failer` provider (any driver: db/dynamo/null)
 *   - pending    → jobs waiting in queue (db: available_at <= now; redis: list keys)
 *   - scheduled  → delayed jobs (db: available_at > now; redis: queues:<name>:delayed zset)
 *   - completed  → Horizon-only (reads horizon:completed_jobs via JobRepository)
 *
 * Each method takes a name search + page index and returns a paginated array
 * of normalised job descriptors. Driver-specific reading is encapsulated;
 * callers only see the unified shape.
 */
class QueueSnapshot
{
    public function __construct(
        protected ConfigRepository $config,
        protected Application $app,
        protected SnipDumper $dumper,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->config->get('snip.queue.enabled', true);
    }

    public function perPage(): int
    {
        return max(1, (int) $this->config->get('snip.queue.per_page', 50));
    }

    public function maxScan(): int
    {
        return max(1, (int) $this->config->get('snip.queue.max_scan', 2000));
    }

    /**
     * Whether the active queue connection is one we can introspect for
     * pending/scheduled state. Failed jobs and Horizon completed jobs are
     * checked independently of this flag.
     */
    public function driverSupportsListing(): bool
    {
        $driver = $this->activeDriver();

        return $driver === 'database' || $driver === 'redis';
    }

    public function activeConnection(): string
    {
        return (string) $this->config->get('queue.default', 'sync');
    }

    public function activeDriver(): string
    {
        $connection = $this->activeConnection();

        return (string) $this->config->get("queue.connections.{$connection}.driver", $connection);
    }

    public function horizonAvailable(): bool
    {
        return interface_exists('Laravel\\Horizon\\Contracts\\JobRepository')
            && $this->app->bound('Laravel\\Horizon\\Contracts\\JobRepository');
    }

    /**
     * Cheap per-state counters used to populate the panel's state-pill badges.
     * Each call is best-effort: missing drivers, unbound failer providers and
     * absent Horizon all return null for the affected key rather than failing.
     *
     * @return array{failed: ?int, pending: ?int, scheduled: ?int, completed: ?int}
     */
    public function counts(bool $includeSilenced = false): array
    {
        return [
            'failed' => $this->countFailed(),
            'pending' => $this->countDriver('pending'),
            'scheduled' => $this->countDriver('scheduled'),
            'completed' => $this->countCompleted($includeSilenced),
        ];
    }

    /**
     * Merge failed/pending/scheduled (and completed when Horizon present) into
     * a single newest-first list. Each row carries a `state` field so callers
     * can render mixed sources without losing origin.
     *
     * @return array{state: string, supported: bool, items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, message: ?string, counts?: array<string, ?int>}
     */
    public function all(string $search = '', int $page = 1, bool $includeSilenced = false): array
    {
        $sources = [
            'failed' => $this->failed($search, 1)['items'] ?? [],
            'pending' => $this->pending($search, 1)['items'] ?? [],
            'scheduled' => $this->scheduled($search, 1)['items'] ?? [],
        ];

        if ($this->horizonAvailable()) {
            $sources['completed'] = $this->completed($search, 1, $includeSilenced)['items'] ?? [];
        }

        // The per-state calls above are already paginated to per_page; for a
        // unified view we re-fetch with max_scan via the count helpers below
        // would be ideal — but to keep latency low we accept the per_page cap
        // per state and slice the combined list to per_page.
        $items = [];
        foreach ($sources as $state => $rows) {
            foreach ($rows as $row) {
                $row['state'] = $state;
                $items[] = $row;
            }
        }

        usort($items, fn ($a, $b) => $this->rowTimestamp($b) <=> $this->rowTimestamp($a));

        $result = $this->paginate('all', true, $items, $page);
        $result['counts'] = $this->counts($includeSilenced);

        return $result;
    }

    /**
     * @return array{state: string, supported: bool, items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, message: ?string}
     */
    public function failed(string $search = '', int $page = 1): array
    {
        $base = $this->emptyResult('failed');

        try {
            $provider = $this->app->bound('queue.failer')
                ? $this->app->make('queue.failer')
                : $this->app->make(FailedJobProviderInterface::class);
        } catch (Throwable $e) {
            $base['message'] = 'Failed-job provider unavailable: '.$e->getMessage();

            return $base;
        }

        try {
            $rows = (array) $provider->all();
        } catch (Throwable $e) {
            $base['message'] = 'Failed-job read error: '.$e->getMessage();

            return $base;
        }

        $items = [];
        foreach ($rows as $row) {
            $payload = $this->decodePayload($this->propertyOrKey($row, 'payload'));
            $name = $this->jobName($payload);

            if (! $this->matches($search, $name)) {
                continue;
            }

            $exception = (string) ($this->propertyOrKey($row, 'exception') ?? '');

            $items[] = [
                'id' => (string) ($this->propertyOrKey($row, 'id') ?? $this->propertyOrKey($row, 'uuid') ?? ''),
                'name' => $name,
                'queue' => (string) ($this->propertyOrKey($row, 'queue') ?? 'default'),
                'connection' => (string) ($this->propertyOrKey($row, 'connection') ?? $this->activeConnection()),
                'attempts' => $this->extractAttempts($payload),
                'failed_at' => $this->toIso($this->propertyOrKey($row, 'failed_at')),
                'exception' => $this->shortenException($exception),
                'exception_full' => $exception,
                'payload' => $this->dumper->dump($payload ?? []),
            ];
        }

        return $this->paginate('failed', true, $items, $page);
    }

    /**
     * @return array{state: string, supported: bool, items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, message: ?string}
     */
    public function pending(string $search = '', int $page = 1): array
    {
        return $this->listJobs('pending', $search, $page);
    }

    /**
     * @return array{state: string, supported: bool, items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, message: ?string}
     */
    public function scheduled(string $search = '', int $page = 1): array
    {
        return $this->listJobs('scheduled', $search, $page);
    }

    /**
     * @return array{state: string, supported: bool, items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, message: ?string}
     */
    public function completed(string $search = '', int $page = 1, bool $includeSilenced = false): array
    {
        $base = $this->emptyResult('completed');

        if (! $this->horizonAvailable()) {
            $base['message'] = 'Completed jobs are tracked by Laravel Horizon. Install Horizon to enable this view.';

            return $base;
        }

        try {
            $repository = $this->app->make('Laravel\\Horizon\\Contracts\\JobRepository');

            $sources = [['jobs' => $repository->getCompleted(), 'silenced' => false]];

            if ($includeSilenced && method_exists($repository, 'getSilenced')) {
                $sources[] = ['jobs' => $repository->getSilenced(), 'silenced' => true];
            }
        } catch (Throwable $e) {
            $base['message'] = 'Horizon read error: '.$e->getMessage();

            return $base;
        }

        $items = [];

        foreach ($sources as $source) {
            foreach ($source['jobs'] as $job) {
                $payload = $this->decodePayload($this->propertyOrKey($job, 'payload'));
                $name = (string) ($this->propertyOrKey($job, 'name') ?? $this->jobName($payload));

                if (! $this->matches($search, $name)) {
                    continue;
                }

                $items[] = [
                    'id' => (string) ($this->propertyOrKey($job, 'id') ?? ''),
                    'name' => $name,
                    'queue' => (string) ($this->propertyOrKey($job, 'queue') ?? 'default'),
                    'connection' => (string) ($this->propertyOrKey($job, 'connection') ?? $this->activeConnection()),
                    'attempts' => $this->extractAttempts($payload),
                    'completed_at' => $this->toIso($this->propertyOrKey($job, 'completed_at')),
                    'reserved_at' => $this->toIso($this->propertyOrKey($job, 'reserved_at')),
                    'silenced' => $source['silenced'],
                    'payload' => $this->dumper->dump($payload ?? []),
                ];
            }
        }

        return $this->paginate('completed', true, $items, $page);
    }

    /**
     * Dispatch pending/scheduled reads onto the right driver-specific path.
     *
     * @return array{state: string, supported: bool, items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, message: ?string}
     */
    protected function listJobs(string $state, string $search, int $page): array
    {
        $base = $this->emptyResult($state);
        $driver = $this->activeDriver();

        try {
            return match ($driver) {
                'database' => $this->paginate($state, true, $this->databaseJobs($state, $search), $page),
                'redis' => $this->paginate($state, true, $this->redisJobs($state, $search), $page),
                default => $this->unsupported($base, "Queue driver \"{$driver}\" cannot be enumerated."),
            };
        } catch (Throwable $e) {
            $base['message'] = ucfirst($state).' read error: '.$e->getMessage();

            return $base;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function databaseJobs(string $state, string $search): array
    {
        $connection = $this->activeConnection();
        $table = (string) $this->config->get("queue.connections.{$connection}.table", 'jobs');
        $dbConnection = $this->config->get("queue.connections.{$connection}.connection") ?: null;

        $now = (int) CarbonImmutable::now()->getTimestamp();

        $query = DB::connection($dbConnection)
            ->table($table)
            ->orderBy('id');

        if ($state === 'pending') {
            $query->where('available_at', '<=', $now)->whereNull('reserved_at');
        } else { // scheduled
            $query->where('available_at', '>', $now);
        }

        $rows = $query->limit($this->maxScan())->get();

        $items = [];
        foreach ($rows as $row) {
            $payload = $this->decodePayload($row->payload ?? null);
            $name = $this->jobName($payload);

            if (! $this->matches($search, $name)) {
                continue;
            }

            $items[] = [
                'id' => (string) ($row->id ?? ''),
                'name' => $name,
                'queue' => (string) ($row->queue ?? 'default'),
                'connection' => $connection,
                'attempts' => (int) ($row->attempts ?? $this->extractAttempts($payload)),
                'available_at' => $this->toIso($row->available_at ?? null),
                'created_at' => $this->toIso($row->created_at ?? null),
                'reserved_at' => $this->toIso($row->reserved_at ?? null),
                'payload' => $this->dumper->dump($payload ?? []),
            ];
        }

        return $items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function redisJobs(string $state, string $search): array
    {
        $connection = $this->activeConnection();
        $redisConnection = (string) ($this->config->get("queue.connections.{$connection}.connection") ?? 'default');
        $queues = $this->resolveRedisQueues($connection);

        $items = [];
        $remaining = $this->maxScan();

        foreach ($queues as $queueName) {
            if ($remaining <= 0) {
                break;
            }

            $payloads = $state === 'pending'
                ? $this->redisPendingPayloads($redisConnection, $queueName, $remaining)
                : $this->redisDelayedPayloads($redisConnection, $queueName, $remaining);

            foreach ($payloads as $entry) {
                $payload = $this->decodePayload($entry['payload']);
                $name = $this->jobName($payload);

                if (! $this->matches($search, $name)) {
                    continue;
                }

                $items[] = array_merge(
                    [
                        'id' => (string) ($payload['id'] ?? $payload['uuid'] ?? ''),
                        'name' => $name,
                        'queue' => $queueName,
                        'connection' => $connection,
                        'attempts' => $this->extractAttempts($payload),
                        'payload' => $this->dumper->dump($payload ?? []),
                    ],
                    $entry['extra'] ?? [],
                );

                $remaining--;
                if ($remaining <= 0) {
                    break;
                }
            }
        }

        return $items;
    }

    /**
     * Resolve the list of queue names to scan on a redis connection. By
     * default we read the user-configured `snip.queue.queues` array. When
     * empty, fall back to the connection's `queue` config and the literal
     * "default".
     *
     * @return array<int, string>
     */
    protected function resolveRedisQueues(string $connection): array
    {
        $configured = $this->config->get('snip.queue.queues');

        if (is_array($configured) && $configured !== []) {
            return array_values(array_unique(array_map('strval', $configured)));
        }

        $default = (string) ($this->config->get("queue.connections.{$connection}.queue") ?? 'default');

        return array_values(array_unique(array_filter([$default, 'default'])));
    }

    /**
     * Read pending jobs from `queues:<name>` LIST (head = next to run).
     *
     * @return array<int, array{payload: string, extra: array<string, mixed>}>
     */
    protected function redisPendingPayloads(string $connection, string $queueName, int $limit): array
    {
        $redis = Redis::connection($connection);
        $key = 'queues:'.$queueName;

        $raw = (array) $redis->lrange($key, 0, $limit - 1);

        $items = [];
        foreach ($raw as $payload) {
            $items[] = ['payload' => (string) $payload, 'extra' => []];
        }

        return $items;
    }

    /**
     * Read scheduled jobs from `queues:<name>:delayed` ZSET. Scores are unix
     * timestamps for when the job becomes available.
     *
     * @return array<int, array{payload: string, extra: array<string, mixed>}>
     */
    protected function redisDelayedPayloads(string $connection, string $queueName, int $limit): array
    {
        $redis = Redis::connection($connection);
        $key = 'queues:'.$queueName.':delayed';

        try {
            $raw = (array) $redis->zrange($key, 0, $limit - 1, ['withscores' => true]);
        } catch (Throwable) {
            $raw = (array) $redis->zrange($key, 0, $limit - 1);
        }

        $items = [];

        // phpredis with withscores → assoc payload=>score; predis → flat list
        if ($raw !== [] && array_keys($raw) !== range(0, count($raw) - 1)) {
            foreach ($raw as $payload => $score) {
                $items[] = [
                    'payload' => (string) $payload,
                    'extra' => ['available_at' => $this->toIso((int) $score)],
                ];
            }

            return $items;
        }

        $i = 0;
        while ($i < count($raw)) {
            $payload = (string) $raw[$i];
            $score = isset($raw[$i + 1]) ? (int) $raw[$i + 1] : null;
            $items[] = [
                'payload' => $payload,
                'extra' => $score !== null ? ['available_at' => $this->toIso($score)] : [],
            ];
            $i += 2;
        }

        return $items;
    }

    protected function decodePayload(mixed $raw): ?array
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Resolve the user-facing job name. Standard Laravel job payloads have
     * `displayName`. Older `commandName` is the unwrapped Command class.
     * Fall back to "(unknown)" so the row still renders.
     */
    protected function jobName(?array $payload): string
    {
        if ($payload === null) {
            return '(unknown)';
        }

        $name = $payload['displayName']
            ?? $payload['commandName']
            ?? $payload['job']
            ?? '(unknown)';

        return (string) $name;
    }

    protected function extractAttempts(?array $payload): int
    {
        return (int) ($payload['attempts'] ?? 0);
    }

    protected function matches(string $search, string $name): bool
    {
        if ($search === '') {
            return true;
        }

        return str_contains(strtolower($name), strtolower($search));
    }

    protected function shortenException(string $text): string
    {
        $first = strtok($text, "\n");
        $first = is_string($first) ? $first : '';

        return mb_strlen($first) > 240 ? mb_substr($first, 0, 240).'…' : $first;
    }

    protected function toIso(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === '0') {
            return null;
        }

        try {
            if (is_numeric($value)) {
                return CarbonImmutable::createFromTimestamp((int) $value)->toIso8601String();
            }

            return CarbonImmutable::parse((string) $value)->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    protected function countFailed(): ?int
    {
        try {
            $provider = $this->app->bound('queue.failer')
                ? $this->app->make('queue.failer')
                : $this->app->make(FailedJobProviderInterface::class);

            return count((array) $provider->all());
        } catch (Throwable) {
            return null;
        }
    }

    protected function countCompleted(bool $includeSilenced): ?int
    {
        if (! $this->horizonAvailable()) {
            return null;
        }

        try {
            $repository = $this->app->make('Laravel\\Horizon\\Contracts\\JobRepository');
            $count = count((array) $repository->getCompleted());

            if ($includeSilenced && method_exists($repository, 'getSilenced')) {
                $count += count((array) $repository->getSilenced());
            }

            return $count;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Cheap count for pending/scheduled across the active driver. Returns null
     * when the driver cannot be enumerated (sqs/beanstalkd/sync).
     */
    protected function countDriver(string $state): ?int
    {
        $driver = $this->activeDriver();

        try {
            if ($driver === 'database') {
                $connection = $this->activeConnection();
                $table = (string) $this->config->get("queue.connections.{$connection}.table", 'jobs');
                $dbConnection = $this->config->get("queue.connections.{$connection}.connection") ?: null;

                $now = (int) CarbonImmutable::now()->getTimestamp();
                $query = DB::connection($dbConnection)->table($table);

                if ($state === 'pending') {
                    return (int) $query->where('available_at', '<=', $now)->whereNull('reserved_at')->count();
                }

                return (int) $query->where('available_at', '>', $now)->count();
            }

            if ($driver === 'redis') {
                $connection = $this->activeConnection();
                $redisConnection = (string) ($this->config->get("queue.connections.{$connection}.connection") ?? 'default');
                $queues = $this->resolveRedisQueues($connection);
                $redis = Redis::connection($redisConnection);

                $total = 0;
                foreach ($queues as $queueName) {
                    if ($state === 'pending') {
                        $total += (int) $redis->llen('queues:'.$queueName);
                    } else {
                        $total += (int) $redis->zcard('queues:'.$queueName.':delayed');
                    }
                }

                return $total;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * Pick the most relevant unix timestamp for sorting a unified row.
     */
    protected function rowTimestamp(array $row): int
    {
        foreach (['failed_at', 'completed_at', 'available_at', 'created_at', 'reserved_at'] as $key) {
            if (! empty($row[$key])) {
                try {
                    return CarbonImmutable::parse((string) $row[$key])->getTimestamp();
                } catch (Throwable) {
                    continue;
                }
            }
        }

        return 0;
    }

    protected function propertyOrKey(mixed $row, string $key): mixed
    {
        if (is_object($row)) {
            return $row->{$key} ?? null;
        }

        if (is_array($row)) {
            return $row[$key] ?? null;
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{state: string, supported: bool, items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, message: ?string}
     */
    protected function paginate(string $state, bool $supported, array $items, int $page): array
    {
        $perPage = $this->perPage();
        $total = count($items);
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        return [
            'state' => $state,
            'supported' => $supported,
            'items' => array_slice($items, $offset, $perPage),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'message' => null,
        ];
    }

    /**
     * @return array{state: string, supported: bool, items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, message: ?string}
     */
    protected function emptyResult(string $state): array
    {
        return [
            'state' => $state,
            'supported' => false,
            'items' => [],
            'total' => 0,
            'page' => 1,
            'per_page' => $this->perPage(),
            'message' => null,
        ];
    }

    /**
     * @param  array{state: string, supported: bool, items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, message: ?string}  $base
     * @return array{state: string, supported: bool, items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, message: ?string}
     */
    protected function unsupported(array $base, string $message): array
    {
        $base['supported'] = false;
        $base['message'] = $message;

        return $base;
    }
}
