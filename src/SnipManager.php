<?php

declare(strict_types=1);

namespace RudolfBruder\LaravelSnip;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use RudolfBruder\LaravelSnip\Support\SnipDumper;

class SnipManager
{
    private const KIND_ENTRIES = 'entries';

    private const KIND_TIMINGS = 'timings';

    private const KIND_MILESTONES = 'milestones';

    /** @var array<int, array{label: ?string, file: ?string, line: ?int, time_ms: float, bytes: ?int, value: array}> */
    protected array $entries = [];

    /** @var array<int, array{label: string, file: ?string, line: ?int, start_ms: float, duration_ms: float}> */
    protected array $timings = [];

    /** @var array<int, array{label: string, file: ?string, line: ?int, time_ms: float}> */
    protected array $milestones = [];

    /** @var array<string, float> */
    protected array $startMarks = [];

    protected float $startedAt;

    public function __construct(
        protected ConfigRepository $config,
        protected SnipDumper $dumper,
    ) {
        $this->startedAt = defined('LARAVEL_START') ? LARAVEL_START : microtime(true);
    }

    public function add(mixed $value, ?string $label = null): self
    {
        if (! $this->canRecord(self::KIND_ENTRIES, count($this->entries), 200)) {
            return $this;
        }

        [$file, $line] = $this->resolveCaller();

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
        if (! $this->enabled()) {
            return $this;
        }

        $this->startMarks[$label] = microtime(true);

        return $this;
    }

    public function timing(string $label): self
    {
        if (! $this->canRecord(self::KIND_TIMINGS, count($this->timings), 500)) {
            return $this;
        }

        $now = microtime(true);
        $origin = $this->startMarks[$label] ?? $this->startedAt;
        [$file, $line] = $this->resolveCaller();

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
        if (! $this->canRecord(self::KIND_MILESTONES, count($this->milestones), 1000)) {
            return $this;
        }

        [$file, $line] = $this->resolveCaller();

        $this->milestones[] = [
            'label' => $label,
            'file' => $file,
            'line' => $line,
            'time_ms' => $this->elapsedMs(),
        ];

        return $this;
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

    public function clear(): self
    {
        $this->entries = [];
        $this->timings = [];
        $this->milestones = [];
        $this->startMarks = [];

        return $this;
    }

    public function enabled(): bool
    {
        return (bool) $this->config->get('snip.enabled', true);
    }

    /**
     * Single guard for the three "record N items per request" methods.
     * Returns true when the manager is enabled and the per-kind cap is not yet
     * reached.
     */
    protected function canRecord(string $kind, int $currentCount, int $defaultMax): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $max = (int) $this->config->get("snip.limits.max_{$kind}_per_request", $defaultMax);

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

    /** @return array{0: ?string, 1: ?int} */
    protected function resolveCaller(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6);

        foreach ($trace as $frame) {
            $file = $frame['file'] ?? null;

            if ($file === null || $this->isInternalFrame($file)) {
                continue;
            }

            return [$file, $frame['line'] ?? null];
        }

        return [null, null];
    }

    protected function isInternalFrame(string $file): bool
    {
        return str_contains($file, '/laravel-snip/src/')
            || str_ends_with($file, '/helpers.php');
    }
}
