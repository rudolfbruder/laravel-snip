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
use ReflectionFunction;
use ReflectionObject;
use Throwable;
use UnitEnum;

class SnipDumper
{
    private const REDACTED_PREVIEW = '***REDACTED***';

    /** @var array<int, true> */
    protected array $seen = [];

    public function __construct(protected ConfigRepository $config) {}

    /**
     * Approximate the in-memory size of a value by measuring its serialized
     * length. Returns null when the value cannot be serialized (closures,
     * resources, PDO handles, etc.).
     */
    public function approximateSize(mixed $value): ?int
    {
        try {
            return strlen(serialize($value));
        } catch (Throwable) {
            return null;
        }
    }

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
        if ($depth > $this->maxDepth()) {
            return $this->node('truncated', '… max depth reached');
        }

        // match (true) dispatches by runtime type. Order matters: more specific
        // types come first so a Model isn't caught by the generic `is_object`
        // arm. Each arm returns a typed tree node.
        return match (true) {
            $value === null => $this->node('null', 'null'),
            is_bool($value) => $this->node('bool', $value ? 'true' : 'false'),
            is_int($value) => $this->node('int', (string) $value),
            is_float($value) => $this->node('float', $this->formatFloat($value)),
            is_string($value) => $this->dumpString($value),
            is_array($value) => $this->dumpArray($value, $depth),
            $value instanceof Closure => $this->dumpClosure($value),
            $value instanceof DateTimeInterface => $this->node('datetime', $value->format(DATE_ATOM)),
            $value instanceof BackedEnum => $this->node('enum', $value::class.'::'.$value->name.' ('.var_export($value->value, true).')'),
            $value instanceof UnitEnum => $this->node('enum', $value::class.'::'.$value->name),
            $value instanceof Model => $this->dumpModel($value, $depth),
            $value instanceof EloquentCollection,
            $value instanceof SupportCollection => $this->dumpCollection($value, $depth),
            is_object($value) => $this->dumpObject($value, $depth),
            is_resource($value) => $this->node('resource', 'resource('.get_resource_type($value).')'),
            default => $this->node('unknown', gettype($value)),
        };
    }

    /**
     * @return array{type: string, preview: string}
     */
    protected function dumpString(string $value): array
    {
        return $this->node('string', $this->truncateString($value));
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @return array{type: string, preview: string, children: array<int, array>}
     */
    protected function dumpArray(array $value, int $depth): array
    {
        return $this->node(
            'array',
            'array('.count($value).')',
            $this->buildChildren($value, count($value), $depth),
        );
    }

    /**
     * @return array{type: string, preview: string, children: array<int, array>}
     */
    protected function dumpModel(Model $model, int $depth): array
    {
        $loadedRelations = $model->getRelations();
        $children = [];

        foreach ($model->attributesToArray() as $attribute => $val) {
            $children[] = $this->wrapKey($attribute, $val, $depth);
        }

        // Already-loaded relations only — never trigger lazy loading.
        foreach ($loadedRelations as $name => $related) {
            $children[] = $this->wrapKey($name, $related, $depth);
        }

        return $this->node('model', $this->modelPreview($model, count($loadedRelations)), $children);
    }

    /**
     * @param  EloquentCollection<int, Model>|SupportCollection<int, mixed>  $collection
     * @return array{type: string, preview: string, children: array<int, array>}
     */
    protected function dumpCollection(EloquentCollection|SupportCollection $collection, int $depth): array
    {
        return $this->node(
            'collection',
            $collection::class.'('.$collection->count().')',
            $this->buildChildren($collection, $collection->count(), $depth),
        );
    }

    /**
     * @return array{type: string, preview: string, children: array<int, array>}
     */
    protected function dumpObject(object $value, int $depth): array
    {
        $hash = spl_object_id($value);

        if (isset($this->seen[$hash])) {
            return $this->node('circular', $value::class.' [circular reference]');
        }

        $this->seen[$hash] = true;

        $children = $this->reflectProperties($value, $depth);

        unset($this->seen[$hash]);

        return $this->node('object', $value::class, $children);
    }

    /**
     * @return array{type: string, preview: string}
     */
    protected function dumpClosure(Closure $closure): array
    {
        return $this->node('closure', $this->closurePreview($closure));
    }

    /**
     * @param  iterable<mixed, mixed>  $items
     * @return array<int, array>
     */
    protected function buildChildren(iterable $items, int $total, int $depth): array
    {
        $max = $this->maxArrayItems();
        $children = [];
        $i = 0;

        foreach ($items as $key => $item) {
            if ($i >= $max) {
                $children[] = $this->node('truncated', '… '.($total - $max).' more items');
                break;
            }

            $children[] = $this->wrapKey((string) $key, $item, $depth);
            $i++;
        }

        return $children;
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
                'preview' => self::REDACTED_PREVIEW,
                'redacted' => true,
            ];
        }

        return ['key' => $key] + $this->convert($value, $depth + 1);
    }

    /**
     * @return array<int, array>
     */
    protected function reflectProperties(object $value, int $depth): array
    {
        $children = [];

        try {
            foreach ((new ReflectionObject($value))->getProperties() as $property) {
                $property->setAccessible(true);

                if (! $property->isInitialized($value)) {
                    continue;
                }

                $children[] = $this->wrapKey($property->getName(), $property->getValue($value), $depth);
            }
        } catch (Throwable $e) {
            $children[] = ['type' => 'error', 'preview' => $e->getMessage()];
        }

        return $children;
    }

    protected function modelPreview(Model $model, int $relationCount): string
    {
        $key = $model->getKey();
        $head = $model::class.($key !== null ? ' #'.$key : ' [unsaved]');

        if ($relationCount === 0) {
            return $head;
        }

        return $head.' ('.$relationCount.' '.($relationCount === 1 ? 'relation' : 'relations').')';
    }

    protected function closurePreview(Closure $closure): string
    {
        try {
            $reflection = new ReflectionFunction($closure);
            $file = basename((string) ($reflection->getFileName() ?: '?'));
            $line = $reflection->getStartLine() ?: 0;

            return 'Closure ('.$file.':'.$line.')';
        } catch (Throwable) {
            return 'Closure';
        }
    }

    protected function truncateString(string $value): string
    {
        $max = $this->maxStringLength();
        $length = mb_strlen($value);

        if ($length <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max).'… ['.($length - $max).' more chars]';
    }

    protected function shouldRedact(string $key): bool
    {
        $needle = strtolower($key);

        foreach ((array) $this->config->get('snip.redact_keys', []) as $candidate) {
            if (strtolower((string) $candidate) === $needle) {
                return true;
            }
        }

        return false;
    }

    protected function formatFloat(float $value): string
    {
        return match (true) {
            is_nan($value) => 'NAN',
            is_infinite($value) => $value > 0 ? 'INF' : '-INF',
            default => rtrim(rtrim(sprintf('%.10F', $value), '0'), '.'),
        };
    }

    /**
     * @param  array<int, array>|null  $children
     * @return array{type: string, preview: string, children?: array<int, array>}
     */
    protected function node(string $type, string $preview, ?array $children = null): array
    {
        $node = ['type' => $type, 'preview' => $preview];

        if ($children !== null) {
            $node['children'] = $children;
        }

        return $node;
    }

    protected function maxDepth(): int
    {
        return (int) $this->config->get('snip.limits.max_depth', 6);
    }

    protected function maxArrayItems(): int
    {
        return (int) $this->config->get('snip.limits.max_array_items', 200);
    }

    protected function maxStringLength(): int
    {
        return (int) $this->config->get('snip.limits.max_string_length', 5000);
    }
}
