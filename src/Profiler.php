<?php

declare(strict_types=1);

namespace RudolfBruder\LaravelSnip;

use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-request profile of where the time went: the lifecycle phases (boot, middleware, controller,
 * render), every measured step as an indented tree, and every SQL, Redis, cache, HTTP and Blade view
 * call with the step it happened in. Shipped to the panel's Profiler tab both as structured data and
 * as a plain-text report.
 *
 * Switched on with `snip.profiler.enabled` and armed by the InjectSnip middleware, so console commands
 * and code running before the middleware stack never record anything. Recording is not gated (the
 * gate needs the session, which starts later); only the report is, via the payload builder. When
 * not armed, measure() only calls the closure.
 *
 * Scoped to the request, so a long-lived worker never carries one request's timings into the next.
 */
class Profiler
{
    /** Slowest SQL statements written per request. */
    private const SLOWEST_QUERIES = 15;

    /** Blade views written per request, the heaviest first. */
    private const HEAVIEST_VIEWS = 30;

    /** Redis keys read more than once, written per request. */
    private const REPEATED_REDIS_KEYS = 20;

    /** SQL is truncated to this many characters in the report. */
    private const SQL_PREVIEW_LENGTH = 400;

    private bool $active = false;

    private ?string $surface = null;

    /** @var array<string, mixed> */
    private array $context = [];

    private ?float $bootedAt = null;
    private ?float $middlewareStartedAt = null;
    private ?float $controllerStartedAt = null;
    private ?float $controllerFinishedAt = null;
    private ?float $responseReadyAt = null;

    /** @var list<array{label: string, depth: int, start: float, duration: float, counters: array<string, array{count: int, ms: float}>}> */
    private array $segments = [];

    /** @var list<int> Indexes into $segments of the steps still running. */
    private array $openSegments = [];

    /** @var array<string, array{count: int, ms: float}> */
    private array $totals = [];

    /** @var list<array{kind: string, at: float, ms: float, label: string, step: string, extra: string}> */
    private array $calls = [];

    /** @var list<array{ms: float, sql: string, connection: string, step: string}> */
    private array $queries = [];

    /** @var array<string, int> */
    private array $cacheEvents = ['hit' => 0, 'miss' => 0, 'write' => 0];

    /** @var list<array{name: string, at: float}> */
    private array $views = [];

    /** @var array<string, array{count: int, ms: float}> Redis commands by key, to spot values read over and over. */
    private array $redisKeys = [];

    public function __construct(
        protected ConfigRepository $config,
        protected AuthFactory $auth,
    ) {}

    public function configured(): bool
    {
        return (bool) $this->config->get('snip.enabled', true)
            && (bool) $this->config->get('snip.profiler.enabled', false);
    }

    public function isEnabled(): bool
    {
        return $this->active;
    }

    /**
     * Arms the profiler for this request. Called by the InjectSnip middleware.
     */
    public function start(): void
    {
        if (! $this->configured()) {
            return;
        }

        $this->active = true;
        $this->middlewareStartedAt ??= microtime(true);
    }

    /**
     * Names the request, so the panel shows the profile even on pages with no other captures.
     *
     * Called first thing in a controller, so it also marks the controller start unless
     * markControllerStarted() already did; a later call renames the request but keeps that mark.
     *
     * @param  array<string, mixed>  $context
     */
    public function surface(string $surface, array $context = []): void
    {
        if (! $this->active) {
            return;
        }

        $this->surface = $surface;
        $this->context = array_merge($this->context, $context);
        $this->markControllerStarted();
    }

    /** @param  array<string, mixed>  $context */
    public function addContext(array $context): void
    {
        if (! $this->active) {
            return;
        }

        $this->context = array_merge($this->context, $context);
    }

    /**
     * Runs the callback and records how long it took, and which calls it made.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function measure(string $label, Closure $callback): mixed
    {
        if (! $this->active) {
            return $callback();
        }

        $index = count($this->segments);
        $this->segments[] = [
            'label' => $label,
            'depth' => count($this->openSegments),
            'start' => microtime(true),
            'duration' => 0.0,
            'counters' => [],
        ];
        $this->openSegments[] = $index;

        try {
            return $callback();
        } finally {
            $this->segments[$index]['duration'] = (microtime(true) - $this->segments[$index]['start']) * 1000;
            array_pop($this->openSegments);
        }
    }

    /**
     * Wraps pipeline pipes so each one is measured on its own.
     *
     * Each pipe is handed an identity `$next`, so its time excludes the pipes after it.
     *
     * @param  list<class-string>  $pipes
     * @return list<class-string|Closure>
     */
    public function measuredPipes(array $pipes, string $prefix = 'pipe'): array
    {
        if (! $this->active) {
            return $pipes;
        }

        return array_map(
            fn (string $pipe): Closure => function (mixed $passable, Closure $next) use ($pipe, $prefix): mixed {
                $result = $this->measure($prefix.' '.class_basename($pipe), fn (): mixed => app($pipe)->handle($passable, fn (mixed $carry): mixed => $carry));

                return $next($result);
            },
            $pipes,
        );
    }

    public function hasCaptures(): bool
    {
        return $this->active && ($this->surface !== null || $this->segments !== []);
    }

    public function markBooted(): void
    {
        $this->bootedAt ??= microtime(true);
    }

    public function markControllerStarted(): void
    {
        $this->controllerStartedAt ??= microtime(true);
    }

    public function markControllerFinished(): void
    {
        $this->controllerFinishedAt ??= microtime(true);
    }

    public function markResponseReady(): void
    {
        $this->responseReadyAt ??= microtime(true);
    }

    /**
     * Records one external call (Elasticsearch, Redis, HTTP, ...) against the step that is running.
     */
    public function recordCall(string $kind, float $durationMs, string $label = '', string $extra = ''): void
    {
        if (! $this->active) {
            return;
        }

        $this->count($kind, $durationMs);

        // Redis is counted only: a page makes hundreds of cache reads and listing them buries the rest.
        if ($kind === 'redis') {
            return;
        }

        $this->calls[] = [
            'kind' => $kind,
            'at' => microtime(true) - ($durationMs / 1000),
            'ms' => $durationMs,
            'label' => $label,
            'step' => $this->currentStep(),
            'extra' => $extra,
        ];
    }

    public function recordQuery(string $sql, float $durationMs, string $connection): void
    {
        if (! $this->active) {
            return;
        }

        $this->count('sql', $durationMs);

        $this->queries[] = [
            'ms' => $durationMs,
            'sql' => $sql,
            'connection' => $connection,
            'step' => $this->currentStep(),
        ];
    }

    /**
     * Notes that a Blade view started rendering.
     *
     * Laravel fires no event when a view finishes, so a view's time is read as the gap until the next
     * view starts: its own code up to its first include, or the code after its last one. Rough, but enough
     * to see which partial a slow render spends its time in.
     */
    public function recordViewStarted(string $name): void
    {
        if (! $this->active) {
            return;
        }

        $this->views[] = ['name' => $name, 'at' => microtime(true)];
    }

    public function recordRedisKey(string $key, float $durationMs): void
    {
        if (! $this->active) {
            return;
        }

        $this->redisKeys[$key] ??= ['count' => 0, 'ms' => 0.0];
        $this->redisKeys[$key]['count']++;
        $this->redisKeys[$key]['ms'] += $durationMs;
    }

    public function recordCacheEvent(string $type): void
    {
        if (! $this->active) {
            return;
        }

        $this->cacheEvents[$type] = ($this->cacheEvents[$type] ?? 0) + 1;
    }

    /**
     * The profile for the panel: structured data to draw it from, plus the same data as plain text
     * under `text`, to read raw or copy. Null when the profiler is not armed.
     *
     * The response is null on Inertia visits, whose payload is built while the response is still
     * being rendered.
     *
     * @return array<string, mixed>|null
     */
    public function report(Request $request, ?Response $response = null): ?array
    {
        if (! $this->active) {
            return null;
        }

        $finishedAt = microtime(true);
        $start = defined('LARAVEL_START') ? LARAVEL_START : ($this->bootedAt ?? $this->middlewareStartedAt ?? $finishedAt);

        $data = $this->snapshot($request, $response, $start, $finishedAt);
        $data['text'] = $this->format($data);

        return $data;
    }

    private function count(string $kind, float $durationMs): void
    {
        $this->totals[$kind] ??= ['count' => 0, 'ms' => 0.0];
        $this->totals[$kind]['count']++;
        $this->totals[$kind]['ms'] += $durationMs;

        foreach ($this->openSegments as $index) {
            $this->segments[$index]['counters'][$kind] ??= ['count' => 0, 'ms' => 0.0];
            $this->segments[$index]['counters'][$kind]['count']++;
            $this->segments[$index]['counters'][$kind]['ms'] += $durationMs;
        }
    }

    private function currentStep(): string
    {
        $index = end($this->openSegments);

        if ($index !== false) {
            return $this->segments[$index]['label'];
        }

        $view = end($this->views);

        return $view === false ? '-' : 'view '.$view['name'];
    }

    /** @return array<string, mixed> */
    private function snapshot(Request $request, ?Response $response, float $start, float $finishedAt): array
    {
        $sinceStart = fn (float $at): float => $this->round(($at - $start) * 1000);

        $squishedQueries = collect($this->queries)
            ->map(fn (array $query): array => ['sql' => (string) str($query['sql'])->squish()] + $query);

        return [
            'surface' => $this->surface,
            'method' => $request->method(),
            'user' => $this->user(),
            'url' => $request->fullUrl(),
            'response' => $response === null ? null : [
                'status' => $response->getStatusCode(),
                'bytes' => $response->getContent() === false ? null : strlen($response->getContent()),
            ],
            'total_ms' => $this->round(($finishedAt - $start) * 1000),
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 1),
            'context' => $this->context,
            'phases' => $this->phases($start, $finishedAt),
            'totals' => $this->counters($this->totals),
            'cache' => $this->cacheEvents,
            'steps' => array_map(fn (array $segment): array => [
                'label' => $segment['label'],
                'depth' => $segment['depth'],
                'start_ms' => $sinceStart($segment['start']),
                'duration_ms' => $this->round($segment['duration']),
                'counters' => $this->counters($segment['counters']),
            ], $this->segments),
            'calls' => array_map(fn (array $call): array => [
                'kind' => $call['kind'],
                'at_ms' => $sinceStart($call['at']),
                'ms' => $this->round($call['ms']),
                'label' => $call['label'],
                'extra' => $call['extra'],
                'step' => $call['step'],
            ], $this->calls),
            'views' => [
                'count' => count($this->views),
                'first_at_ms' => $this->views === [] ? null : $sinceStart($this->views[0]['at']),
                'heaviest' => $this->heaviestViews($finishedAt),
            ],
            'redis_keys' => collect($this->redisKeys)
                ->filter(fn (array $key): bool => $key['count'] > 1)
                ->sortByDesc('count')
                ->take(self::REPEATED_REDIS_KEYS)
                ->map(fn (array $stats, string $key): array => ['key' => $key, 'count' => $stats['count'], 'ms' => $this->round($stats['ms'])])
                ->values()
                ->all(),
            'queries' => [
                'count' => count($this->queries),
                'slowest' => $squishedQueries
                    ->sortByDesc('ms')
                    ->take(self::SLOWEST_QUERIES)
                    ->map(fn (array $query): array => ['ms' => $this->round($query['ms'])] + $query)
                    ->values()
                    ->all(),
                'repeated' => $squishedQueries
                    ->groupBy('sql')
                    ->filter(fn ($group): bool => $group->count() > 2)
                    ->sortByDesc(fn ($group): int => $group->count())
                    ->take(10)
                    ->map(fn ($group, string $sql): array => ['sql' => $sql, 'count' => $group->count(), 'ms' => $this->round($group->sum('ms'))])
                    ->values()
                    ->all(),
            ],
        ];
    }

    /**
     * Who the request ran for, by id only: the report is meant to be copied around.
     */
    private function user(): string
    {
        $user = $this->auth->guard($this->config->get('snip.guard'))->user();

        return $user === null ? 'guest' : 'user #'.$user->getAuthIdentifier();
    }

    /**
     * Blade views by total time, the heaviest first.
     *
     * @return list<array{name: string, count: int, ms: float}>
     */
    private function heaviestViews(float $finishedAt): array
    {
        $end = $this->responseReadyAt ?? $finishedAt;
        $timed = [];

        foreach ($this->views as $position => $view) {
            $next = $this->views[$position + 1]['at'] ?? $end;
            $timed[] = ['name' => $view['name'], 'ms' => ($next - $view['at']) * 1000];
        }

        return collect($timed)
            ->groupBy('name')
            ->map(fn ($group, string $name): array => ['name' => $name, 'count' => $group->count(), 'ms' => $this->round($group->sum('ms'))])
            ->sortByDesc('ms')
            ->take(self::HEAVIEST_VIEWS)
            ->values()
            ->all();
    }

    /**
     * The report is built while the response is still on its way out, so the last phase runs up to
     * the moment the report was written; sending the response is never part of it.
     *
     * @return list<array{label: string, ms: float|null}>
     */
    private function phases(float $start, float $finishedAt): array
    {
        $phase = fn (string $label, ?float $from, ?float $to): array => [
            'label' => $label,
            'ms' => $from === null || $to === null ? null : $this->round(($to - $from) * 1000),
        ];

        $phases = [
            $phase('boot', $start, $this->bootedAt),
            $phase('until middleware', $this->bootedAt, $this->middlewareStartedAt),
        ];

        if ($this->controllerStartedAt === null) {
            $phases[] = $phase('middleware + controller', $this->middlewareStartedAt, $this->controllerFinishedAt);
        } else {
            $phases[] = $phase('middleware before controller', $this->middlewareStartedAt, $this->controllerStartedAt);
            $phases[] = $phase('controller', $this->controllerStartedAt, $this->controllerFinishedAt);
        }

        $phases[] = $phase('render (props, Inertia SSR, view)', $this->controllerFinishedAt ?? $this->middlewareStartedAt, $this->responseReadyAt ?? $finishedAt);

        if ($this->responseReadyAt !== null) {
            $phases[] = $phase('middleware after', $this->responseReadyAt, $finishedAt);
        }

        return $phases;
    }

    /**
     * @param  array<string, array{count: int, ms: float}>  $counters
     * @return list<array{kind: string, count: int, ms: float}>
     */
    private function counters(array $counters): array
    {
        return collect($counters)
            ->map(fn (array $counter, string $kind): array => ['kind' => $kind, 'count' => $counter['count'], 'ms' => $this->round($counter['ms'])])
            ->values()
            ->all();
    }

    private function round(float $ms): float
    {
        return round($ms, 2);
    }

    /** @param  array<string, mixed>  $data */
    private function format(array $data): string
    {
        $offset = fn (float $ms): string => sprintf('+%6.0f ms', $ms);
        $lines = [];

        $lines[] = sprintf(
            '[%s] %s %s%s | %s | total %.0f ms | peak memory %.1f MB',
            $data['surface'] ?? 'request',
            $data['method'],
            $data['url'],
            $data['response'] === null ? '' : ' '.$data['response']['status'],
            $data['user'],
            $data['total_ms'],
            $data['peak_memory_mb'],
        );

        if ($data['context'] !== []) {
            $lines[] = '  context: '.json_encode($data['context'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }

        $lines[] = '  phases: '.implode(' | ', array_map(
            fn (array $phase): string => $phase['label'].' '.($phase['ms'] === null ? '?' : sprintf('%.0f ms', $phase['ms'])),
            $data['phases'],
        ));

        $lines[] = '  totals: '.$this->formatCounters($data['totals'])
            .sprintf(' | cache hit %d / miss %d / write %d', $data['cache']['hit'], $data['cache']['miss'], $data['cache']['write'])
            .match (true) {
                $data['response'] === null => '',
                $data['response']['bytes'] === null => ' | response streamed',
                default => sprintf(' | response %.1f KB', $data['response']['bytes'] / 1024),
            };

        if ($data['steps'] !== []) {
            $lines[] = '  steps:';

            foreach ($data['steps'] as $step) {
                $lines[] = sprintf(
                    '    %s %9.1f ms  %s%s%s',
                    $offset($step['start_ms']),
                    $step['duration_ms'],
                    str_repeat('  ', $step['depth']),
                    $step['label'],
                    $step['counters'] === [] ? '' : '  ['.$this->formatCounters($step['counters']).']',
                );
            }
        }

        if ($data['calls'] !== []) {
            $lines[] = '  calls:';

            foreach ($data['calls'] as $call) {
                $lines[] = sprintf(
                    '    %s %9.1f ms  %-7s %s%s  (in: %s)',
                    $offset($call['at_ms']),
                    $call['ms'],
                    $call['kind'],
                    $call['label'],
                    $call['extra'] === '' ? '' : '  '.$call['extra'],
                    $call['step'],
                );
            }
        }

        if ($data['views']['count'] > 0) {
            $lines[] = sprintf(
                '  blade views (%d rendered, first at +%.0f ms, heaviest %d by time until the next view starts):',
                $data['views']['count'],
                $data['views']['first_at_ms'],
                count($data['views']['heaviest']),
            );

            foreach ($data['views']['heaviest'] as $view) {
                $lines[] = sprintf('    %9.1f ms  %4dx  %s', $view['ms'], $view['count'], $view['name']);
            }
        }

        if ($data['redis_keys'] !== []) {
            $lines[] = '  redis keys read more than once:';

            foreach ($data['redis_keys'] as $key) {
                $lines[] = sprintf('    %4dx %8.1f ms  %s', $key['count'], $key['ms'], $key['key']);
            }
        }

        if ($data['queries']['count'] > 0) {
            $lines[] = sprintf('  slowest sql (%d of %d):', count($data['queries']['slowest']), $data['queries']['count']);

            foreach ($data['queries']['slowest'] as $query) {
                $lines[] = sprintf(
                    '    %9.1f ms  [%s] %s  (in: %s)',
                    $query['ms'],
                    $query['connection'],
                    str($query['sql'])->limit(self::SQL_PREVIEW_LENGTH),
                    $query['step'],
                );
            }

            if ($data['queries']['repeated'] !== []) {
                $lines[] = '  repeated sql (possible N+1):';

                foreach ($data['queries']['repeated'] as $repeated) {
                    $lines[] = sprintf('    %3dx %8.1f ms  %s', $repeated['count'], $repeated['ms'], str($repeated['sql'])->limit(self::SQL_PREVIEW_LENGTH));
                }
            }
        }

        return implode(PHP_EOL, $lines);
    }

    /** @param  list<array{kind: string, count: int, ms: float}>  $counters */
    private function formatCounters(array $counters): string
    {
        if ($counters === []) {
            return 'no external calls';
        }

        return implode(', ', array_map(
            fn (array $counter): string => sprintf('%s %d / %.0f ms', $counter['kind'], $counter['count'], $counter['ms']),
            $counters,
        ));
    }
}
