<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import ValueTree from './ValueTree.vue';
import CopyValueButton from './CopyValueButton.vue';
import type { InertiaDeferredProps, InertiaPage, SnipNode } from './types';
import { childValueOf, jsValueToNode } from './dumper';
import { formatBytes } from './format';
import { filterTree, highlightSegments, matches } from './search';
import { canCopyValues } from './clipboard';
import { readStorage, writeStorage } from './storage';

const props = defineProps<{
    page: InertiaPage;
    deferred: InertiaDeferredProps;
}>();

/** The shared prop the package ships its own payload in; it is the panel's data, not the page's. */
const SNIP_PROP = '_snip';

type Badge = { text: string; kind: 'deferred' | 'pending' | 'merge'; title: string };
type Row = { key: string; node: SnipNode | null; badges: Badge[]; bytes: number | null };

const MERGE_KINDS: Array<[string, keyof InertiaPage, string]> = [
    ['merge', 'mergeProps', 'Appended to the current value on partial reloads'],
    ['prepend', 'prependProps', 'Prepended to the current value on partial reloads'],
    ['deep merge', 'deepMergeProps', 'Deep-merged into the current value on partial reloads'],
];

/** Props in the order the page received them, or by the size of their JSON; props without a size go last. */
const ORDERS = ['page', 'size-desc', 'size-asc'] as const;
type Order = (typeof ORDERS)[number];

const ORDER_LABELS: Record<Order, string> = { page: 'page order', 'size-desc': 'size ↓', 'size-asc': 'size ↑' };
const ORDER_STORAGE_KEY = 'laravel-snip:inertia-order';

const order = ref<Order>(readStorage(ORDER_STORAGE_KEY, ORDERS) ?? 'page');
watch(order, (next) => writeStorage(ORDER_STORAGE_KEY, next));

function cycleOrder(): void {
    order.value = ORDERS[(ORDERS.indexOf(order.value) + 1) % ORDERS.length];
}

const expanded = ref<Set<string>>(new Set());
const query = ref('');
const searchInput = ref<HTMLInputElement | null>(null);

function clearSearch(): void {
    query.value = '';
    searchInput.value?.focus();
}
const term = computed<string>(() => query.value.trim().toLowerCase());

const encoder = new TextEncoder();

/** What a value adds to the Inertia response: the UTF-8 length of its JSON. */
function jsonBytes(value: unknown): number | null {
    try {
        const json = JSON.stringify(value);
        return json === undefined ? null : encoder.encode(json).length;
    } catch {
        return null;
    }
}

/** Sizes are shown where they can grow: arrays, objects and strings. */
function sized(value: unknown): boolean {
    return typeof value === 'string' || (value !== null && typeof value === 'object');
}

const deferredGroups = computed<Map<string, string>>(() => {
    const groups = new Map<string, string>();
    for (const [group, names] of Object.entries(props.deferred)) {
        names.forEach((name) => groups.set(name, group));
    }
    return groups;
});

const rows = computed<Row[]>(() => {
    const values = props.page.props;
    const keys = Object.keys(values).filter((key) => key !== SNIP_PROP);
    const pending = [...deferredGroups.value.keys()].filter((key) => !(key in values));

    return [...keys, ...pending].map((key) => ({
        key,
        node: key in values ? jsValueToNode(values[key]) : null,
        badges: badgesFor(key, key in values),
        bytes: key in values && sized(values[key]) ? jsonBytes(values[key]) : null,
    }));
});

const sortedRows = computed<Row[]>(() => {
    if (order.value === 'page') return rows.value;

    const dir = order.value === 'size-desc' ? -1 : 1;
    return rows.value.slice().sort((a, b) => {
        if (a.bytes === null || b.bytes === null) return (a.bytes === null ? 1 : 0) - (b.bytes === null ? 1 : 0);
        return (a.bytes - b.bytes) * dir;
    });
});

const totalBytes = computed<number | null>(() => {
    const { [SNIP_PROP]: _snip, ...pageProps } = props.page.props;
    return jsonBytes(pageProps);
});

/** Rows matching the search, each trimmed to the parts of its tree that match; all rows when not searching. */
const visibleRows = computed<Row[]>(() => {
    if (term.value === '') return sortedRows.value;

    return sortedRows.value.flatMap((row) => {
        if (row.node === null) return matches(row.key, term.value) ? [row] : [];

        const node = filterTree({ ...row.node, key: row.key }, term.value);
        return node ? [{ ...row, node }] : [];
    });
});

// Searching opens every prop with a match inside it; clearing the search brings back what was open before.
let expandedBeforeSearch: Set<string> | null = null;

watch(term, (now, before) => {
    if (now === '') {
        expanded.value = expandedBeforeSearch ?? new Set();
        expandedBeforeSearch = null;
        return;
    }
    if (before === '') expandedBeforeSearch = expanded.value;
    expanded.value = new Set(visibleRows.value.filter((row) => row.node?.open).map((row) => row.key));
});

const pendingCount = computed<number>(() => rows.value.filter((row) => row.node === null).length);
const loadedCount = computed<number>(() => rows.value.length - pendingCount.value);

function badgesFor(key: string, loaded: boolean): Badge[] {
    const badges: Badge[] = [];
    const group = deferredGroups.value.get(key);

    if (group !== undefined) {
        const suffix = group === 'default' ? '' : ` · ${group}`;
        badges.push(loaded
            ? { text: `deferred${suffix}`, kind: 'deferred', title: 'Loaded by a follow-up request after the page rendered' }
            : { text: `deferred${suffix} · pending`, kind: 'pending', title: 'Not loaded yet: the follow-up request has not finished' });
    }

    for (const [label, field, title] of MERGE_KINDS) {
        for (const path of (props.page[field] as string[] | undefined) ?? []) {
            if (path !== key && !path.startsWith(`${key}.`)) continue;

            const nested = path === key ? '' : ` ${path.slice(key.length + 1)}`;
            const matchOn = (props.page.matchPropsOn ?? [])
                .filter((m) => m.startsWith(`${path}.`))
                .map((m) => m.slice(path.length + 1));
            const matchNote = matchOn.length ? `, matching items on ${matchOn.join(', ')}` : '';

            badges.push({ text: `${label}${nested}`, kind: 'merge', title: `${title}${matchNote}` });
        }
    }

    return badges;
}

function expandable(row: Row): boolean {
    return (row.node?.children?.length ?? 0) > 0;
}

function toggle(row: Row): void {
    if (!expandable(row)) return;

    const next = new Set(expanded.value);
    next.has(row.key) ? next.delete(row.key) : next.add(row.key);
    expanded.value = next;
}
</script>

<template>
    <div class="wrap">
        <header class="head">
            <span class="component-chip" :title="'Page component'">{{ page.component }}</span>
            <code class="url" :title="page.url">{{ page.url }}</code>
            <span class="muted">
                {{ loadedCount }} prop{{ loadedCount === 1 ? '' : 's' }}<span v-if="totalBytes !== null"> · {{ formatBytes(totalBytes) }}</span><span v-if="pendingCount"> · {{ pendingCount }} deferred pending</span>
            </span>
        </header>

        <div v-if="rows.length > 0" class="search">
            <div class="search__field">
                <input
                    ref="searchInput"
                    v-model="query"
                    type="search"
                    class="search__input"
                    placeholder="Search prop names and values…"
                    aria-label="Search props"
                    @keydown.esc="query = ''"
                />
                <button
                    v-if="query"
                    type="button"
                    class="search__clear"
                    title="Clear search (Esc)"
                    aria-label="Clear search"
                    @click="clearSearch"
                >×</button>
            </div>
            <span v-if="term" class="muted search__count">{{ visibleRows.length }} of {{ rows.length }} match</span>
            <button type="button" class="sort" title="Sort props: page order, largest first, smallest first" @click="cycleOrder">
                {{ ORDER_LABELS[order] }}
            </button>
        </div>

        <div v-if="rows.length === 0" class="empty">This page has no props.</div>
        <div v-else-if="visibleRows.length === 0" class="empty">No prop names or values match "{{ query.trim() }}".</div>

        <ul v-else class="props">
            <li v-for="row in visibleRows" :key="row.key" class="prop">
                <div class="prop__row">
                    <button
                        type="button"
                        class="prop__head"
                        :disabled="!expandable(row)"
                        :aria-expanded="expandable(row) ? expanded.has(row.key) : undefined"
                        @click="toggle(row)"
                    >
                        <span class="caret" :class="{ 'caret--open': expanded.has(row.key), 'caret--hidden': !expandable(row) }">▸</span>
                        <span class="key"><template v-for="(seg, i) in highlightSegments(row.key, term)" :key="i"><mark v-if="seg.hit">{{ seg.text }}</mark><template v-else>{{ seg.text }}</template></template></span>
                        <span
                            v-for="badge in row.badges"
                            :key="badge.text"
                            class="badge"
                            :class="`badge--${badge.kind}`"
                            :title="badge.title"
                        >{{ badge.text }}</span>
                        <span v-if="row.node" class="preview" :title="row.node.preview"><template v-for="(seg, i) in highlightSegments(row.node.preview, expandable(row) ? undefined : term)" :key="i"><mark v-if="seg.hit">{{ seg.text }}</mark><template v-else>{{ seg.text }}</template></template></span>
                        <span v-else class="preview preview--pending">not loaded</span>
                        <span v-if="row.bytes !== null" class="size" title="Size of this prop's JSON in the response">{{ formatBytes(row.bytes) }}</span>
                    </button>
                    <CopyValueButton
                        v-if="row.node"
                        class="copy"
                        :target="row.key"
                        :value="page.props[row.key]"
                        :label="`the value of ${row.key}`"
                    />
                    <span v-else-if="canCopyValues" class="copy" aria-hidden="true"></span>
                </div>

                <div v-if="row.node && expanded.has(row.key)" class="prop__body">
                    <!-- Keyed on the search term so the trees remount and pick up the open paths of a new search. -->
                    <ValueTree
                        v-for="(child, index) in row.node.children"
                        :key="`${term}:${index}`"
                        :node="child"
                        :initially-open="false"
                        :depth="1"
                        :highlight="term"
                        :value="childValueOf(page.props[row.key], child)"
                    />
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

.head {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--snip-border);
    font-size: 12px;
}

.component-chip {
    background: var(--snip-accent-strong);
    color: #ffffff;
    border-radius: 9999px;
    padding: 2px 10px;
    font-size: 11px;
    font-weight: 600;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}

.url {
    color: var(--snip-text-strong);
    overflow-wrap: anywhere;
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

.empty {
    color: var(--snip-text-faint);
    text-align: center;
    padding: 28px 0;
    font-size: 13px;
}

.props {
    list-style: none;
    margin: 0;
    padding: 0;
    background: var(--snip-surface);
    border: 1px solid var(--snip-border);
    border-radius: 8px;
    overflow: hidden;
}

.prop + .prop {
    border-top: 1px solid var(--snip-border-soft);
}

.prop__row {
    display: flex;
    align-items: center;
}

.prop__row:hover {
    background: var(--snip-hover);
}

.prop__head {
    flex: 1;
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    min-width: 0;
    padding: 7px 12px;
    background: transparent;
    border: none;
    color: inherit;
    font: inherit;
    font-size: 12px;
    text-align: left;
    cursor: pointer;
}

.prop__head:disabled {
    cursor: default;
}

.prop__head:focus-visible {
    outline: 2px solid var(--snip-accent);
    outline-offset: -2px;
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

.caret--hidden {
    visibility: hidden;
}

.key {
    color: var(--snip-text-strong);
    font-weight: 600;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    flex-shrink: 0;
}

.badge {
    flex-shrink: 0;
    border-radius: 9999px;
    padding: 0 7px;
    font-size: 10px;
    line-height: 16px;
    font-weight: 600;
    letter-spacing: 0.02em;
    border: 1px solid currentColor;
    cursor: help;
}

/* Filled with dark text, like the queue state badges: these tokens are too light for text on the light theme. */
.badge--deferred {
    background: var(--snip-ok);
    border-color: var(--snip-ok);
    color: #000000;
}

.badge--pending {
    background: var(--snip-warn);
    border-color: var(--snip-warn);
    color: #000000;
}

.badge--merge {
    color: var(--snip-accent);
}

.preview {
    flex: 1;
    min-width: 0;
    text-align: right;
    color: var(--snip-text-faint);
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 11px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.size {
    flex-shrink: 0;
    min-width: 52px;
    text-align: right;
    color: var(--snip-text-muted);
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 11px;
    font-variant-numeric: tabular-nums;
}

/* Revealed while the row is hovered or focused; pending rows get an empty one, to keep the sizes lined up. */
.copy {
    width: 20px;
    height: 20px;
    margin-right: 8px;
    flex-shrink: 0;
}

.prop__row:hover .copy,
.prop__row:focus-within .copy {
    visibility: visible;
}

.search {
    display: flex;
    align-items: center;
    gap: 10px;
}

.search__field {
    position: relative;
    flex: 1;
    min-width: 0;
    display: flex;
}

.search__input {
    flex: 1;
    min-width: 0;
    background: var(--snip-surface);
    border: 1px solid var(--snip-border);
    color: var(--snip-text);
    border-radius: 6px;
    /* Room on the right for the clear button. */
    padding: 5px 28px 5px 10px;
    font-size: 12px;
    font-family: inherit;
    outline: none;
}

.search__input:focus {
    border-color: var(--snip-accent);
}

/* The browser's own clear button only exists in some engines; ours replaces it everywhere. */
.search__input::-webkit-search-cancel-button {
    appearance: none;
}

.search__clear {
    position: absolute;
    top: 50%;
    right: 4px;
    transform: translateY(-50%);
    width: 20px;
    height: 20px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    background: transparent;
    border: none;
    border-radius: 4px;
    color: var(--snip-text-muted);
    font: inherit;
    font-size: 16px;
    line-height: 1;
    cursor: pointer;
}

.search__clear:hover {
    background: var(--snip-hover);
    color: var(--snip-text-strong);
}

.search__clear:focus-visible {
    outline: 2px solid var(--snip-accent);
    outline-offset: -2px;
}

.search__count {
    flex-shrink: 0;
}

.sort {
    flex-shrink: 0;
    background: transparent;
    border: 1px solid var(--snip-border);
    color: var(--snip-text);
    border-radius: 6px;
    padding: 5px 10px;
    font-size: 11px;
    font-family: inherit;
    white-space: nowrap;
    cursor: pointer;
}

.sort:hover {
    background: var(--snip-surface-2);
}

.sort:focus-visible {
    outline: 2px solid var(--snip-accent);
    outline-offset: -2px;
}

mark {
    background: color-mix(in srgb, var(--snip-warn) 45%, transparent);
    color: inherit;
    border-radius: 2px;
}

.preview--pending {
    font-family: inherit;
    font-style: italic;
}

.prop__body {
    padding: 2px 12px 8px 26px;
}
</style>
