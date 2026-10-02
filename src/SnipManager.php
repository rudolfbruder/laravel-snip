<?php

declare(strict_types=1);

namespace RudolfBruder\LaravelSnip;

use Closure;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use RudolfBruder\LaravelSnip\Support\CallerResolver;
use RudolfBruder\LaravelSnip\Support\CapturingDecision;
use RudolfBruder\LaravelSnip\Support\PendingPayloadStore;
use RudolfBruder\LaravelSnip\Support\SnipDumper;

/**
 * Per-request collector for snip entries, timings, and milestones.
 *
 * The manager owns *only* the collection state. Capturing eligibility,
 * caller resolution, dumping, and session-flash plumbing live in dedicated
 * collaborators so this class stays focused on the public recording API.
 *
 * Bound as a scoped instance — see SnipServiceProvider — so long-lived
 * runtimes (Octane, queue workers) start each request with an empty buffer.
 */
class SnipManager
{
    /** @var array<int, array{label: ?string, file: ?string, line: ?int, time_ms: float, bytes: ?int, value: array}> */
    protected array $entries = [];

    /** @var array<int, array{label: string, file: ?string, line: ?int, start_ms: float, duration_ms: float}> */
    protected array $timings = [];

    /** @var array<int, array{label: string, file: ?string, line: ?int, time_ms: float}> */
    protected array $milestones = [];

    /** @var array<string, float> */
    protected array $startMarks = [];

    protected float $startedAt;

    protected bool $hydratedFromSession = false;

    public function __construct(
        protected ConfigRepository $config,
        protected SnipDumper $dumper,
        protected CapturingDecision $decision,
        protected CallerResolver $callerResolver,
        protected PendingPayloadStore $pendingStore,
        protected Profiler $profiler,
    ) {
        $this->startedAt = defined('LARAVEL_START') ? LARAVEL_START : microtime(true);
    }

    public function add(mixed $value, ?string $label = null): self
    {
        if (! $this->canRecord('entries', count($this->entries))) {
            return $this;
        }

        [$file, $line] = $this->callerResolver->resolve();

        $this->entries[] = [
            'label' => $label,
            'file' => $file,
            'line' => $line,
            'time_ms' => $this->elapsedMs(),
            'bytes' => $this->shouldMeasureMemory() ? $this->dumper->approximateSize($value) : null,
            'value' => $this->dumper->dump($value),
        ];

        return $this;
    }

    public function start(string $label): self
    {
        if (! $this->isCapturing()) {
            return $this;
        }

        $this->startMarks[$label] = microtime(true);

        return $this;
    }

    public function timing(string $label): self
    {
        if (! $this->canRecord('timings', count($this->timings))) {
            return $this;
        }

        $now = microtime(true);
        $origin = $this->startMarks[$label] ?? $this->startedAt;
        [$file, $line] = $this->callerResolver->resolve();

        $this->timings[] = [
            'label' => $label,
            'file' => $file,
            'line' => $line,
            'start_ms' => $this->msSinceStart($origin),
            'duration_ms' => $this->msBetween($origin, $now),
        ];

        return $this;
    }

    public function milestone(string $label): self
    {
        if (! $this->canRecord('milestones', count($this->milestones))) {
            return $this;
        }

        [$file, $line] = $this->callerResolver->resolve();

        $this->milestones[] = [
            'label' => $label,
            'file' => $file,
            'line' => $line,
            'time_ms' => $this->elapsedMs(),
        ];

        return $this;
    }

    /**
     * The request's profiler, for the calls without a shortcut here (addContext, recordCall,
     * measuredPipes, markControllerStarted, ...).
     */
    public function profiler(): Profiler
    {
        return $this->profiler;
    }

    /**
     * Names the request for the Profiler tab. See Profiler::surface().
     *
     * @param  array<string, mixed>  $context
     */
    public function surface(string $surface, array $context = []): self
    {
        $this->profiler->surface($surface, $context);

        return $this;
    }

    /**
     * Runs the callback as a Profiler step and returns its result. See Profiler::measure().
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function measure(string $label, Closure $callback): mixed
    {
        return $this->profiler->measure($label, $callback);
    }

    /** @return array<int, array{label: ?string, file: ?string, line: ?int, time_ms: float, bytes: ?int, value: array}> */
    public function entries(): array
    {
        return $this->entries;
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /** @return array<int, array{label: string, file: ?string, line: ?int, start_ms: float, duration_ms: float}> */
    public function timings(): array
    {
        return $this->timings;
    }

    public function timingsCount(): int
    {
        return count($this->timings);
    }

    /** @return array<int, array{label: string, file: ?string, line: ?int, time_ms: float}> */
    public function milestones(): array
    {
        return $this->milestones;
    }

    public function milestonesCount(): int
    {
        return count($this->milestones);
    }

    /**
     * Snapshot of the current request's captures, suitable for round-tripping
     * across a redirect via session flash.
     *
     * @return array{entries: array, timings: array, milestones: array}
     */
    public function payload(): array
    {
        return [
            'entries' => $this->entries,
            'timings' => $this->timings,
            'milestones' => $this->milestones,
        ];
    }

    /**
     * Re-hydrate captures recorded on a prior request into the current
     * collection state. Used directly when replaying a known payload, and
     * indirectly via ensureHydratedFromSession().
     *
     * @param array{entries?: array, timings?: array, milestones?: array} $payload
     */
    public function replay(array $payload): self
    {
        foreach ((array) ($payload['entries'] ?? []) as $entry) {
            $this->entries[] = $entry;
        }

        foreach ((array) ($payload['timings'] ?? []) as $timing) {
            $this->timings[] = $timing;
        }

        foreach ((array) ($payload['milestones'] ?? []) as $milestone) {
            $this->milestones[] = $milestone;
        }

        return $this;
    }

    /**
     * Pull any session-flashed payload from the prior request and merge it
     * into the current manager state. Idempotent: subsequent calls within
     * the same request are no-ops.
     *
     * Called from both the InjectSnip middleware (so HTML responses contain
     * carried captures) and the Inertia share callback (so SPA navigation
     * responses contain them as `page.props._snip`).
     */
    public function ensureHydratedFromSession(?Request $request = null): self
    {
        if ($this->hydratedFromSession) {
            return $this;
        }

        $request = $request ?? request();

        if ($request === null) {
            return $this;
        }

        $this->hydratedFromSession = true;

        if (! $this->isCapturing()) {
            $this->pendingStore->forget($request);

            return $this;
        }

        $payload = $this->pendingStore->pull($request);

        if ($payload !== null) {
            $this->replay($payload);
        }

        return $this;
    }

    public function clear(): self
    {
        $this->entries = [];
        $this->timings = [];
        $this->milestones = [];
        $this->startMarks = [];
        $this->hydratedFromSession = false;
        $this->decision->reset();

        return $this;
    }

    public function enabled(): bool
    {
        return $this->decision->enabled();
    }

    public function isCapturing(): bool
    {
        return $this->decision->allows();
    }

    protected function canRecord(string $kind, int $currentCount): bool
    {
        if (! $this->isCapturing()) {
            return false;
        }

        $max = (int) $this->config->get("snip.limits.max_{$kind}_per_request");

        return $currentCount < $max;
    }

    protected function shouldMeasureMemory(): bool
    {
        return (bool) $this->config->get('snip.show_memory', true);
    }

    protected function elapsedMs(): float
    {
        return $this->msBetween($this->startedAt, microtime(true));
    }

    protected function msSinceStart(float $timestamp): float
    {
        return $this->msBetween($this->startedAt, $timestamp);
    }

    protected function msBetween(float $from, float $to): float
    {
        return round(($to - $from) * 1000, 2);
    }
}
