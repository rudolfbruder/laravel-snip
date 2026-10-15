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
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Routing\Events\PreparingResponse;
use Illuminate\Routing\Events\ResponsePrepared;
use Illuminate\Support\InteractsWithTime;
use RudolfBruder\LaravelSnip\Profiler;

/**
 * Feeds the framework's own events into the request's Profiler: SQL, cache hits/misses/writes,
 * Redis commands, outgoing HTTP calls, queued jobs, Blade views and the controller/render boundaries.
 *
 * Registered only when the profiler is switched on in config, so apps that leave it off pay
 * nothing. Calls the framework fires no event for (Elasticsearch, other SDKs) are recorded by the
 * app itself through Profiler::recordCall().
 */
class ProfilerListeners
{
    use InteractsWithTime;

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

        $this->events->listen(JobQueueing::class, fn () => $this->profiler()->markJobQueueing());

        $this->events->listen(JobQueued::class, function (JobQueued $event): void {
            $this->profiler()->recordJobQueued($this->jobName($event->payload, $event->job), $this->queueTarget($event));
        });

        // The sync driver fires no JobQueued: it runs the job on the spot, between these events.
        $this->events->listen(JobProcessing::class, function (JobProcessing $event): void {
            if ($event->job instanceof SyncJob) {
                $this->profiler()->startSyncJob($event->job->resolveName());
            }
        });

        $this->events->listen(JobProcessed::class, function (JobProcessed $event): void {
            if ($event->job instanceof SyncJob) {
                $this->profiler()->finishSyncJob(failed: false);
            }
        });

        $this->events->listen(JobExceptionOccurred::class, function (JobExceptionOccurred $event): void {
            if ($event->job instanceof SyncJob) {
                $this->profiler()->finishSyncJob(failed: true);
            }
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

    /** The name queue dashboards show for a job: the payload's displayName, else the job's class. */
    private function jobName(mixed $payload, mixed $job): string
    {
        $decoded = is_string($payload) ? json_decode($payload, true) : null;

        if (is_array($decoded) && is_string($decoded['displayName'] ?? null)) {
            return $decoded['displayName'];
        }

        return is_object($job) ? $job::class : (string) $job;
    }

    /** Where the job went, such as `redis:emails, delay 60s`. */
    private function queueTarget(JobQueued $event): string
    {
        // Laravel 10 fires JobQueued without the queue name and the delay. A null queue means the
        // connection's default one.
        $queue = property_exists($event, 'queue')
            ? ($event->queue ?? $this->app->make('config')->get("queue.connections.{$event->connectionName}.queue"))
            : null;
        $delay = $this->secondsUntil($event->delay ?? 0);

        $target = is_string($queue) && $queue !== '' ? $event->connectionName.':'.$queue : (string) $event->connectionName;

        return $delay > 0 ? "{$target}, delay {$delay}s" : $target;
    }

    protected function profiler(): Profiler
    {
        return $this->app->make(Profiler::class);
    }
}
