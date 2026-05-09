import type { SnipNode } from './types';

const MAX_DEPTH = 6;
const MAX_ARRAY_ITEMS = 200;
const MAX_STRING_LENGTH = 5000;

const TRUNCATED = (msg: string): SnipNode => ({ type: 'truncated', preview: msg });

/**
 * Convert any JS value into the same SnipNode tree shape the PHP SnipDumper
 * produces, so the existing ValueTree component can render it.
 */
export function jsValueToNode(value: unknown, depth = 0, seen: WeakSet<object> = new WeakSet()): SnipNode {
    if (depth > MAX_DEPTH) return TRUNCATED('… max depth reached');

    if (value === null) return { type: 'null', preview: 'null' };
    if (value === undefined) return { type: 'null', preview: 'undefined' };

    switch (typeof value) {
        case 'boolean':
            return { type: 'bool', preview: value ? 'true' : 'false' };
        case 'number':
            return { type: Number.isInteger(value) ? 'int' : 'float', preview: String(value) };
        case 'bigint':
            return { type: 'int', preview: (value as bigint).toString() };
        case 'string':
            return dumpString(value);
        case 'function':
            return { type: 'closure', preview: `function ${(value as Function).name || 'anonymous'}()` };
        case 'symbol':
            return { type: 'unknown', preview: (value as symbol).toString() };
    }

    return dumpObjectLike(value, depth, seen);
}

function dumpString(value: string): SnipNode {
    if (value.length <= MAX_STRING_LENGTH) {
        return { type: 'string', preview: value };
    }
    return {
        type: 'string',
        preview: value.slice(0, MAX_STRING_LENGTH) + '… [' + (value.length - MAX_STRING_LENGTH) + ' more chars]',
    };
}

function dumpObjectLike(value: object, depth: number, seen: WeakSet<object>): SnipNode {
    if (seen.has(value)) {
        return { type: 'circular', preview: '[circular reference]' };
    }
    seen.add(value);

    if (value instanceof Date) return { type: 'datetime', preview: value.toISOString() };
    if (value instanceof RegExp) return { type: 'string', preview: value.toString() };
    if (value instanceof Map) return dumpMap(value, depth, seen);
    if (value instanceof Set) return dumpSet(value, depth, seen);
    if (Array.isArray(value)) return dumpArray(value, depth, seen);

    return dumpPlainObject(value, depth, seen);
}

function dumpArray(value: unknown[], depth: number, seen: WeakSet<object>): SnipNode {
    const total = value.length;
    const limit = Math.min(total, MAX_ARRAY_ITEMS);
    const children: SnipNode[] = [];

    for (let i = 0; i < limit; i++) {
        children.push({ key: String(i), ...jsValueToNode(value[i], depth + 1, seen) });
    }
    if (total > limit) children.push(TRUNCATED('… ' + (total - limit) + ' more items'));

    return { type: 'array', preview: `array(${total})`, children };
}

function dumpMap(value: Map<unknown, unknown>, depth: number, seen: WeakSet<object>): SnipNode {
    const children: SnipNode[] = [];
    let i = 0;
    for (const [k, v] of value.entries()) {
        if (i++ >= MAX_ARRAY_ITEMS) {
            children.push(TRUNCATED('… ' + (value.size - MAX_ARRAY_ITEMS) + ' more items'));
            break;
        }
        children.push({ key: String(k), ...jsValueToNode(v, depth + 1, seen) });
    }
    return { type: 'collection', preview: `Map(${value.size})`, children };
}

function dumpSet(value: Set<unknown>, depth: number, seen: WeakSet<object>): SnipNode {
    const children: SnipNode[] = [];
    let i = 0;
    for (const v of value.values()) {
        if (i >= MAX_ARRAY_ITEMS) {
            children.push(TRUNCATED('… ' + (value.size - MAX_ARRAY_ITEMS) + ' more items'));
            break;
        }
        children.push({ key: String(i++), ...jsValueToNode(v, depth + 1, seen) });
    }
    return { type: 'collection', preview: `Set(${value.size})`, children };
}

function dumpPlainObject(value: object, depth: number, seen: WeakSet<object>): SnipNode {
    const ctor = value.constructor;
    const ctorName = ctor && ctor.name ? ctor.name : 'Object';
    const keys = Object.keys(value as Record<string, unknown>);
    const limit = Math.min(keys.length, MAX_ARRAY_ITEMS);
    const children: SnipNode[] = [];

    for (let i = 0; i < limit; i++) {
        const key = keys[i];
        children.push({ key, ...jsValueToNode((value as Record<string, unknown>)[key], depth + 1, seen) });
    }
    if (keys.length > limit) children.push(TRUNCATED('… ' + (keys.length - limit) + ' more items'));

    return { type: 'object', preview: ctorName, children };
}
