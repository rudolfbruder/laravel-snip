<?php

declare(strict_types=1);

namespace RudolfBruder\LaravelSnip\Support;

/**
 * Walks the call stack to find the first userland frame outside of the
 * laravel-snip package. The result is shown in the panel sidebar so
 * developers can jump straight from a captured value to the line that
 * recorded it.
 */
class CallerResolver
{
    private const STACK_DEPTH = 8;

    /** @return array{0: ?string, 1: ?int} */
    public function resolve(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::STACK_DEPTH);

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
        return str_contains($file, DIRECTORY_SEPARATOR.'laravel-snip'.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR)
            || str_ends_with($file, 'laravel-snip'.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'helpers.php');
    }
}
