<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import type { SnipCache, SnipCacheKey, SnipNode } from './types';
import { formatBytes } from './format';
import ValueTree from './ValueTree.vue';

const props = defineProps<{
    cache: SnipCache | null;
    valueUrl: string | null;
}>();

type SortField = 'key' | 'ttl' | 'size';
type SortDir = 'asc' | 'desc';

const FIELD_CYCLE: SortField[] = ['key', 'ttl', 'size'];

type ValueState = {
    status: 'loading' | 'loaded' | 'missing' | 'error';
    value: SnipNode | null;
    bytes: number | null;
    type: string | null;
    truncated: boolean;
    count: number | null;
    error: string | null;
};

const sortField = ref<SortField>('key');
const sortDir = ref<SortDir>('asc');
const filter = ref('');
const expanded = ref<Set<string>>(new Set());
const values = reactive<Record<string, ValueState>>({});

const filtered = computed<SnipCacheKey[]>(() => {
    const keys = props.cache?.keys ?? [];
    const needle = filter.value.trim().toLowerCase();
    return needle === '' ? keys : keys.filter((k) => k.key.toLowerCase().includes(needle));
});

const sorted = computed<SnipCacheKey[]>(() => {
    const copy = filtered.value.slice();
    const dir = sortDir.value === 'asc' ? 1 : -1;

    if (sortField.value === 'ttl') {
        copy.sort((a, b) => ((a.ttl ?? Number.POSITIVE_INFINITY) - (b.ttl ?? Number.POSITIVE_INFINITY)) * dir);
    } else if (sortField.value === 'size') {
        copy.sort((a, b) => ((a.bytes ?? -1) - (b.bytes ?? -1)) * dir);
    } else {
        copy.sort((a, b) => a.key.localeCompare(b.key) * dir);
    }
    return copy;
});

const canInspect = computed<boolean>(() => typeof props.valueUrl === 'string' && props.valueUrl !== '');

function cycleField() {
    const idx = FIELD_CYCLE.indexOf(sortField.value);
    sortField.value = FIELD_CYCLE[(idx + 1) % FIELD_CYCLE.length];
}

function toggleDir() {
    sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
}

function fieldLabel(field: SortField): string {
    return { key: 'key', ttl: 'ttl', size: 'size' }[field];
}

function dirLabel(field: SortField, dir: SortDir): string {
    if (field === 'key') {
        return dir === 'asc' ? 'A→Z' : 'Z→A';
    }
    return dir === 'asc' ? '↑' : '↓';
}

function formatTtl(ttl: number | null): string {
    if (ttl === null) return 'forever';
    if (ttl <= 0) return 'expired';
    if (ttl < 60) return `${ttl}s`;
    if (ttl < 3600) return `${Math.round(ttl / 60)}m`;
    if (ttl < 86400) return `${Math.round(ttl / 3600)}h`;
    return `${Math.round(ttl / 86400)}d`;
}

function isHashed(k: SnipCacheKey): boolean {
    return k.hashed === true;
}

async function toggleRow(k: SnipCacheKey): Promise<void> {
    if (isHashed(k) || !canInspect.value) return;

    if (expanded.value.has(k.key)) {
        expanded.value.delete(k.key);
        expanded.value = new Set(expanded.value);
        return;
    }

    expanded.value.add(k.key);
    expanded.value = new Set(expanded.value);

    if (!values[k.key] || values[k.key].status === 'error') {
        await fetchValue(k.key);
    }
}

async function fetchValue(key: string): Promise<void> {
    if (!props.valueUrl) return;

    values[key] = { status: 'loading', value: null, bytes: null, type: null, truncated: false, count: null, error: null };

    try {
        const url = new URL(props.valueUrl, window.location.origin);
        url.searchParams.set('key', key);

        const res = await fetch(url.toString(), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });

        if (!res.ok) {
            let message = `HTTP ${res.status}`;
            try {
                const body = await res.json() as { message?: string; error?: string };
                if (body.message) message = body.message;
                else if (body.error) message = body.error;
            } catch {
                // body wasn't JSON — keep the status code message
            }
            values[key] = {
                status: 'error',
                value: null,
                bytes: null,
                type: null,
                truncated: false,
                count: null,
                error: message,
            };
            return;
        }

        const data = await res.json() as {
            found: boolean;
            value: SnipNode | null;
            bytes: number | null;
            type: string | null;
            truncated?: boolean;
            count?: number | null;
        };

        values[key] = data.found
            ? {
                status: 'loaded',
                value: data.value,
                bytes: data.bytes,
                type: data.type ?? null,
                truncated: data.truncated === true,
                count: data.count ?? null,
                error: null,
            }
            : { status: 'missing', value: null, bytes: null, type: null, truncated: false, count: null, error: null };
    } catch (e) {
        values[key] = {
            status: 'error',
            value: null,
            bytes: null,
            type: null,
            truncated: false,
            count: null,
            error: e instanceof Error ? e.message : 'fetch failed',
        };
    }
}

async function copyKey(event: MouseEvent, key: string): Promise<void> {
    event.stopPropagation();
    try {
        await navigator.clipboard.writeText(key);
    } catch {
        // silent
    }
}
</script>

<template>
    <div class="wrap">
        <header class="head">
            <div class="driver-row">
                <span class="driver-chip">{{ cache?.driver ?? 'unknown' }}</span>
                <span v-if="cache?.prefix" class="muted">prefix: <code>{{ cache.prefix }}</code></span>
                <span v-if="cache" class="muted">
                    {{ cache.keys.length }} key{{ cache.keys.length === 1 ? '' : 's' }}<span v-if="cache.truncated"> (capped)</span>
                </span>
            </div>
            <div v-if="cache?.supported" class="controls">
                <input
                    v-model="filter"
                    type="search"
                    class="filter"
                    placeholder="Filter keys…"
                />
                <button type="button" class="sort" @click="cycleField" :title="'Sort by'">
                    {{ fieldLabel(sortField) }}
                </button>
                <button type="button" class="sort sort--dir" @click="toggleDir" :title="'Toggle direction'">
                    {{ dirLabel(sortField, sortDir) }}
                </button>
            </div>
        </header>

        <p v-if="cache?.message" class="notice">{{ cache.message }}</p>

        <div v-if="!cache" class="empty">Cache snapshot not available.</div>
        <div v-else-if="!cache.supported && cache.keys.length === 0" class="empty">
            Driver "<code>{{ cache.driver }}</code>" cannot be enumerated.
        </div>
        <div v-else-if="cache.keys.length === 0" class="empty">No keys in cache.</div>
        <div v-else-if="sorted.length === 0" class="empty">No keys match "{{ filter }}".</div>

        <ul v-else class="list">
            <li
                v-for="k in sorted"
                :key="k.key"
                class="row"
                :class="{ 'row--expandable': canInspect && !isHashed(k), 'row--expanded': expanded.has(k.key) }"
            >
                <div class="row__head" @click="toggleRow(k)">
                    <span
                        v-if="canInspect && !isHashed(k)"
                        class="caret"
                        :class="{ 'caret--open': expanded.has(k.key) }"
                    >▸</span>
                    <span
                        v-else-if="!canInspect && !isHashed(k)"
                        class="caret caret--disabled"
                        title="Value lookup disabled (no route)"
                    >·</span>
                    <span v-if="isHashed(k)" class="hash-chip">hash</span>
                    <span class="key" :title="isHashed(k) ? 'SHA1 hash — original key not recoverable' : k.key">{{ k.key }}</span>
                    <span class="spacer" />
                    <button
                        class="copy-btn"
                        type="button"
                        :title="'Copy key'"
                        @click="copyKey($event, k.key)"
                    >copy</button>
                    <span class="ttl">ttl: {{ formatTtl(k.ttl) }}</span>
                    <span v-if="k.bytes !== null" class="bytes">{{ formatBytes(k.bytes) }}</span>
                </div>

                <div v-if="expanded.has(k.key)" class="row__body">
                    <div v-if="values[k.key]?.status === 'loading'" class="muted">Loading value…</div>
                    <div v-else-if="values[k.key]?.status === 'missing'" class="muted">Key not present (may have expired between snapshot and lookup).</div>
                    <div v-else-if="values[k.key]?.status === 'error'" class="error">
                        Failed to load value: {{ values[k.key]?.error }}
                    </div>
                    <div v-else-if="values[k.key]?.status === 'loaded' && values[k.key]?.value">
                        <div class="value-meta">
                            <span v-if="values[k.key]?.type" class="type-chip">{{ values[k.key]!.type }}</span>
                            <span v-if="values[k.key]?.count !== null" class="muted">
                                {{ values[k.key]!.count }} item{{ values[k.key]!.count === 1 ? '' : 's' }}<span v-if="values[k.key]?.truncated"> · preview capped</span>
                            </span>
                            <span v-if="values[k.key]?.bytes !== null" class="muted">size: {{ formatBytes(values[k.key]!.bytes!) }}</span>
                        </div>
                        <ValueTree :node="values[k.key]!.value!" :initially-open="true" :depth="0" />
                    </div>
                </div>
            </li>
        </ul>
    </div>
</template>

<style scoped>
.wrap {
    display: flex;
    flex-direction: column;
    gap: 12px;
    color: var(--snip-text);
}

.empty {
    color: var(--snip-text-faint);
    text-align: center;
    padding: 28px 0;
    font-size: 13px;
}

.head {
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--snip-border);
}

.driver-row {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    font-size: 12px;
}

.driver-chip {
    background: var(--snip-accent-strong);
    color: #ffffff;
    border-radius: 9999px;
    padding: 2px 10px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.muted {
    color: var(--snip-text-muted);
    font-size: 12px;
}

code {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    background: var(--snip-surface-2);
    padding: 1px 4px;
    border-radius: 3px;
}

.controls {
    display: flex;
    align-items: center;
    gap: 8px;
}

.filter {
    flex: 1;
    background: var(--snip-surface);
    border: 1px solid var(--snip-border);
    color: var(--snip-text);
    border-radius: 6px;
    padding: 5px 10px;
    font-size: 12px;
    font-family: inherit;
    outline: none;
}

.filter:focus {
    border-color: var(--snip-accent);
}

.sort {
    background: transparent;
    border: 1px solid var(--snip-border);
    color: var(--snip-text);
    border-radius: 6px;
    padding: 5px 10px;
    font-size: 11px;
    cursor: pointer;
    font-family: inherit;
    white-space: nowrap;
}

.sort:hover {
    background: var(--snip-surface-2);
}

.sort--dir {
    min-width: 44px;
    text-align: center;
}

.notice {
    margin: 0;
    color: var(--snip-text-muted);
    font-size: 11px;
    padding: 6px 10px;
    background: var(--snip-surface);
    border: 1px solid var(--snip-border);
    border-radius: 6px;
}

.list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.row {
    background: var(--snip-surface);
    border: 1px solid var(--snip-border);
    border-radius: 8px;
    overflow: hidden;
}

.row__head {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    font-size: 12px;
}

.row--expandable .row__head {
    cursor: pointer;
}

.row--expandable:hover .row__head {
    background: var(--snip-surface-2);
}

.caret {
    color: var(--snip-text-faint);
    font-size: 10px;
    width: 12px;
    text-align: center;
    transition: transform 0.12s ease;
    flex-shrink: 0;
}

.caret--open {
    transform: rotate(90deg);
}

.caret--disabled {
    color: var(--snip-text-fainter);
    cursor: default;
}

.key {
    color: var(--snip-text-strong);
    font-weight: 600;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 12px;
    word-break: break-all;
}

.spacer {
    flex: 1;
}

.copy-btn {
    background: transparent;
    border: 1px solid var(--snip-border);
    color: var(--snip-text-muted);
    border-radius: 4px;
    padding: 1px 6px;
    font-size: 10px;
    font-family: inherit;
    cursor: pointer;
}

.copy-btn:hover {
    background: var(--snip-surface-3);
    color: var(--snip-text);
}

.hash-chip {
    background: var(--snip-chip-bg);
    color: var(--snip-text-faint);
    padding: 0 6px;
    border-radius: 9999px;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    font-family: inherit;
}

.ttl,
.bytes {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 11px;
    color: var(--snip-text-faint);
    font-variant-numeric: tabular-nums;
    flex-shrink: 0;
}

.row__body {
    padding: 10px 12px 12px 32px;
    border-top: 1px solid var(--snip-border);
    background: var(--snip-bg);
}

.size-line {
    margin-bottom: 8px;
    font-size: 11px;
}

.value-meta {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 8px;
    font-size: 11px;
}

.type-chip {
    background: var(--snip-accent);
    color: #ffffff;
    border-radius: 4px;
    padding: 1px 8px;
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.error {
    color: var(--snip-bad);
    font-size: 12px;
}
</style>
