<?php

declare(strict_types=1);

namespace RudolfBruder\LaravelSnip\Support;

use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection as SupportCollection;
use ReflectionClass;
use ReflectionFunction;
use ReflectionObject;
use Throwable;
use UnitEnum;

class SnipDumper
{
    /** @var array<int, true> */
    protected array $seen = [];

    public function __construct(protected ConfigRepository $config) {}

    /**
     * @return array{type: string, preview: string, children?: array<int, array>, redacted?: bool}
     */
    public function dump(mixed $value): array
    {
        $this->seen = [];

        return $this->convert($value, 0);
    }

    /**
     * @return array{type: string, preview: string, children?: array<int, array>, redacted?: bool}
     */
    protected function convert(mixed $value, int $depth): array
    {
        $maxDepth = (int) $this->config->get('snip.limits.max_depth', 6);

        if ($depth > $maxDepth) {
            return ['type' => 'truncated', 'preview' => '… max depth reached'];
        }

        return match (true) {
            $value === null => ['type' => 'null', 'preview' => 'null'],
            is_bool($value) => ['type' => 'bool', 'preview' => $value ? 'true' : 'false'],
            is_int($value) => ['type' => 'int', 'preview' => (string) $value],
            is_float($value) => ['type' => 'float', 'preview' => $this->formatFloat($value)],
            is_string($value) => $this->dumpString($value),
            is_array($value) => $this->dumpArray($value, $depth),
            $value instanceof Closure => $this->dumpClosure($value),
            $value instanceof DateTimeInterface => [
                'type' => 'datetime',
                'preview' => $value->format(DATE_ATOM),
            ],
            $value instanceof BackedEnum => [
                'type' => 'enum',
                'preview' => $value::class.'::'.$value->name.' ('.var_export($value->value, true).')',
            ],
            $value instanceof UnitEnum => [
                'type' => 'enum',
                'preview' => $value::class.'::'.$value->name,
            ],
            $value instanceof Model => $this->dumpModel($value),
            $value instanceof EloquentCollection || $value instanceof SupportCollection => $this->dumpCollection($value, $depth),
            is_object($value) => $this->dumpObject($value, $depth),
            is_resource($value) => [
                'type' => 'resource',
                'preview' => 'resource('.get_resource_type($value).')',
            ],
            default => ['type' => 'unknown', 'preview' => gettype($value)],
        };
    }

    /**
     * @return array{type: string, preview: string}
     */
    protected function dumpString(string $value): array
    {
        $max = (int) $this->config->get('snip.limits.max_string_length', 5000);
        $length = mb_strlen($value);

        if ($length > $max) {
            $value = mb_substr($value, 0, $max).'… ['.($length - $max).' more chars]';
        }

        return ['type' => 'string', 'preview' => $value];
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @return array{type: string, preview: string, children: array<int, array>}
     */
    protected function dumpArray(array $value, int $depth): array
    {
        $max = (int) $this->config->get('snip.limits.max_array_items', 200);
        $count = count($value);
        $children = [];
        $i = 0;

        foreach ($value as $key => $item) {
            if ($i >= $max) {
                $children[] = [
                    'type' => 'truncated',
                    'preview' => '… '.($count - $max).' more items',
                ];
                break;
            }

            $children[] = $this->wrapKey((string) $key, $item, $depth);
            $i++;
        }

        return [
            'type' => 'array',
            'preview' => 'array('.$count.')',
            'children' => $children,
        ];
    }

    /**
     * @return array{type: string, preview: string, children: array<int, array>}
     */
    protected function dumpModel(Model $model): array
    {
        $key = $model->getKey();
        $preview = $model::class.($key !== null ? ' #'.$key : ' [unsaved]');

        $children = [];

        foreach ($model->attributesToArray() as $attribute => $val) {
            $children[] = $this->wrapKey($attribute, $val, 0);
        }

        return [
            'type' => 'model',
            'preview' => $preview,
            'children' => $children,
        ];
    }

    /**
     * @param  EloquentCollection<int, Model>|SupportCollection<int, mixed>  $collection
     * @return array{type: string, preview: string, children: array<int, array>}
     */
    protected function dumpCollection(EloquentCollection|SupportCollection $collection, int $depth): array
    {
        $max = (int) $this->config->get('snip.limits.max_array_items', 200);
        $count = $collection->count();
        $children = [];
        $i = 0;

        foreach ($collection as $key => $item) {
            if ($i >= $max) {
                $children[] = ['type' => 'truncated', 'preview' => '… '.($count - $max).' more items'];
                break;
            }

            $children[] = $this->wrapKey((string) $key, $item, $depth);
            $i++;
        }

        return [
            'type' => 'collection',
            'preview' => $collection::class.'('.$count.')',
            'children' => $children,
        ];
    }

    /**
     * @return array{type: string, preview: string, children: array<int, array>}
     */
    protected function dumpObject(object $value, int $depth): array
    {
        $hash = spl_object_id($value);

        if (isset($this->seen[$hash])) {
            return [
                'type' => 'circular',
                'preview' => $value::class.' [circular reference]',
            ];
        }

        $this->seen[$hash] = true;

        $children = [];

        try {
            $reflection = new ReflectionObject($value);
            foreach ($reflection->getProperties() as $property) {
                $property->setAccessible(true);
                if (! $property->isInitialized($value)) {
                    continue;
                }

                $children[] = $this->wrapKey($property->getName(), $property->getValue($value), $depth);
            }
        } catch (Throwable $e) {
            $children[] = ['type' => 'error', 'preview' => $e->getMessage()];
        }

        unset($this->seen[$hash]);

        return [
            'type' => 'object',
            'preview' => $value::class,
            'children' => $children,
        ];
    }

    /**
     * @return array{type: string, preview: string}
     */
    protected function dumpClosure(Closure $closure): array
    {
        try {
            $reflection = new ReflectionFunction($closure);
            $file = $reflection->getFileName() ?: '?';
            $line = $reflection->getStartLine() ?: 0;
            $preview = 'Closure ('.basename((string) $file).':'.$line.')';
        } catch (Throwable) {
            $preview = 'Closure';
        }

        return ['type' => 'closure', 'preview' => $preview];
    }

    /**
     * @return array{key: string, type: string, preview: string, children?: array<int, array>, redacted?: bool}
     */
    protected function wrapKey(string $key, mixed $value, int $depth): array
    {
        if ($this->shouldRedact($key)) {
            return [
                'key' => $key,
                'type' => 'redacted',
                'preview' => '***REDACTED***',
                'redacted' => true,
            ];
        }

        return ['key' => $key] + $this->convert($value, $depth + 1);
    }

    protected function shouldRedact(string $key): bool
    {
        $redacted = (array) $this->config->get('snip.redact_keys', []);
        $needle = strtolower($key);

        foreach ($redacted as $candidate) {
            if (strtolower((string) $candidate) === $needle) {
                return true;
            }
        }

        return false;
    }

    protected function formatFloat(float $value): string
    {
        if (is_nan($value)) {
            return 'NAN';
        }

        if (is_infinite($value)) {
            return $value > 0 ? 'INF' : '-INF';
        }

        return rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
    }
}
