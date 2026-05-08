<?php

declare(strict_types=1);

namespace RudolfBruder\LaravelSnip;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use RudolfBruder\LaravelSnip\Support\SnipDumper;

class SnipManager
{
    /**
     * @var array<int, array{label: ?string, file: ?string, line: ?int, time_ms: float, value: array}>
     */
    protected array $entries = [];

    protected float $startedAt;

    public function __construct(
        protected ConfigRepository $config,
        protected SnipDumper $dumper,
    ) {
        $this->startedAt = defined('LARAVEL_START') ? LARAVEL_START : microtime(true);
    }

    public function add(mixed $value, ?string $label = null): self
    {
        if (! $this->enabled()) {
            return $this;
        }

        $maxEntries = (int) $this->config->get('snip.limits.max_entries_per_request', 200);

        if (count($this->entries) >= $maxEntries) {
            return $this;
        }

        [$file, $line] = $this->resolveCaller();

        $this->entries[] = [
            'label' => $label,
            'file' => $file,
            'line' => $line,
            'time_ms' => round((microtime(true) - $this->startedAt) * 1000, 2),
            'value' => $this->dumper->dump($value),
        ];

        return $this;
    }

    /**
     * @return array<int, array{label: ?string, file: ?string, line: ?int, time_ms: float, value: array}>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    public function count(): int
    {
        return count($this->entries);
    }

    public function clear(): self
    {
        $this->entries = [];

        return $this;
    }

    public function enabled(): bool
    {
        return (bool) $this->config->get('snip.enabled', true);
    }

    /**
     * @return array{0: ?string, 1: ?int}
     */
    protected function resolveCaller(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6);

        foreach ($trace as $frame) {
            $file = $frame['file'] ?? null;

            if ($file === null) {
                continue;
            }

            if (str_contains($file, '/laravel-snip/src/') || str_ends_with($file, '/helpers.php')) {
                continue;
            }

            return [$file, $frame['line'] ?? null];
        }

        return [null, null];
    }
}
