<?php

declare(strict_types=1);

namespace RudolfBruder\LaravelSnip\Support;

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Routing\Events\PreparingResponse;
use Illuminate\Routing\Events\ResponsePrepared;
use RudolfBruder\LaravelSnip\Profiler;

/**
 * Feeds the framework's own events into the request's Profiler: SQL, cache hits/misses/writes,
 * Redis commands, outgoing HTTP calls, Blade views and the controller/render boundaries.
 *
 * Registered only when the profiler is switched on in config, so apps that leave it off pay
 * nothing. Calls the framework fires no event for (Elasticsearch, other SDKs) are recorded by the
 * app itself through Profiler::recordCall().
 */
class ProfilerListeners
{
    /** Redis commands whose first parameter is a script, not a key. */
    private const SCRIPT_COMMANDS = ['eval', 'evalsha', 'eval_ro', 'evalsha_ro'];

    public function __construct(
        protected Container $app,
        protected Dispatcher $events,
    ) {}

    public function register(): void
    {
        $this->events->listen(QueryExecuted::class, function (QueryExecuted $event): void {
            $this->profiler()->recordQuery($event->sql, (float) $event->time, (string) $event->connectionName);
        });

        $this->events->listen(CacheHit::class, fn () => $this->profiler()->recordCacheEvent('hit'));
        $this->events->listen(CacheMissed::class, fn () => $this->profiler()->recordCacheEvent('miss'));
        $this->events->listen(KeyWritten::class, fn () => $this->profiler()->recordCacheEvent('write'));

        $this->events->listen(CommandExecuted::class, function (CommandExecuted $event): void {
            $profiler = $this->profiler();
            $profiler->recordCall('redis', (float) $event->time);

            $key = $event->parameters[0] ?? null;

            if (is_string($key) && ! in_array(strtolower($event->command), self::SCRIPT_COMMANDS, true)) {
                $profiler->recordRedisKey($key, (float) $event->time);
            }
        });

        $this->events->listen(ResponseReceived::class, function (ResponseReceived $event): void {
            $seconds = $event->response->transferStats?->getTransferTime() ?? 0.0;

            $this->profiler()->recordCall(
                'http',
                $seconds * 1000,
                $event->request->method().' '.$event->request->url(),
                (string) $event->response->status(),
            );
        });

        // 'composing:' fires when a view starts rendering; 'creating:' fires when it is only made.
        $this->events->listen('composing:*', function (string $event, array $payload): void {
            $view = $payload[0] ?? null;

            if ($view instanceof View) {
                $this->profiler()->recordViewStarted($view->name());
            }
        });

        // Both fire once per Router::prepareResponse(); the first pair brackets turning the
        // controller's return value into a response, which is where views and Inertia render.
        $this->events->listen(PreparingResponse::class, fn () => $this->profiler()->markControllerFinished());
        $this->events->listen(ResponsePrepared::class, fn () => $this->profiler()->markResponseReady());

        $this->app->afterResolving('redis', fn ($redis) => $redis->enableEvents());

        if ($this->app->resolved('redis')) {
            $this->app->make('redis')->enableEvents();
        }

        $this->app->booted(fn () => $this->profiler()->markBooted());
    }

    protected function profiler(): Profiler
    {
        return $this->app->make(Profiler::class);
    }
}
