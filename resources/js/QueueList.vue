<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import type { SnipQueueCounts, SnipQueueItem, SnipQueueResponse, SnipQueueState } from './types';
import ValueTree from './ValueTree.vue';

const props = defineProps<{
    url: string | null;
    driver: string | null;
    supportsListing: boolean;
    horizonAvailable: boolean;
}>();

const ALL_STATES: SnipQueueState[] = ['all', 'failed', 'pending', 'scheduled', 'completed'];

const state = ref<SnipQueueState>('all');
const latestCounts = ref<SnipQueueCounts | null>(null);
const search = ref('');
const page = ref(1);
const includeSilenced = ref(false);
const expanded = ref<Set<string>>(new Set());

type LoadState = {
    status: 'idle' | 'loading' | 'loaded' | 'error';
    data: SnipQueueResponse | null;
    error: string | null;
};

const responses = reactive<Record<string, LoadState>>({});

const silencedActive = computed(() => (state.value === 'completed' || state.value === 'all') && includeSilenced.value);

const cacheKey = computed(() => `${state.value}|${search.value}|${page.value}|${silencedActive.value ? 'silenced' : 'plain'}`);

const current = computed<LoadState>(() => responses[cacheKey.value] ?? { status: 'idle', data: null, error: null });

const items = computed<SnipQueueItem[]>(() => current.value.data?.items ?? []);
const total = computed<number>(() => current.value.data?.total ?? 0);
const perPage = computed<number>(() => current.value.data?.per_page ?? 50);
const pageCount = computed<number>(() => Math.max(1, Math.ceil(total.value / perPage.value)));
const supported = computed<boolean>(() => current.value.data?.supported ?? true);
const message = computed<string | null>(() => current.value.data?.message ?? null);

const stateDisabled = computed<Record<SnipQueueState, boolean>>(() => ({
    all: false,
    failed: false,
    pending: !props.supportsListing,
    scheduled: !props.supportsListing,
    completed: !props.horizonAvailable,
}));

watch(state, () => {
    page.value = 1;
    expanded.value = new Set();
    ensureLoaded();
});

watch(search, () => {
    page.value = 1;
    ensureLoaded();
});

watch(page, () => {
    expanded.value = new Set();
    ensureLoaded();
});

watch(includeSilenced, () => {
    if (state.value !== 'completed' && state.value !== 'all') return;
    page.value = 1;
    expanded.value = new Set();
    ensureLoaded();
});

watch(() => props.url, () => ensureLoaded(), { immediate: true });

function ensureLoaded(): void {
    if (!props.url) return;
    const key = cacheKey.value;
    if (responses[key] && responses[key].status === 'loaded') return;
    fetchPage(key);
}

async function fetchPage(key: string): Promise<void> {
    if (!props.url) return;
    responses[key] = { status: 'loading', data: null, error: null };

    try {
        const url = new URL(props.url, window.location.origin);
        url.searchParams.set('state', state.value);
        if (search.value) url.searchParams.set('q', search.value);
        url.searchParams.set('page', String(page.value));
        if (silencedActive.value) {
            url.searchParams.set('include_silenced', '1');
        }

        const res = await fetch(url.toString(), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });

        if (!res.ok) {
            let msg = `HTTP ${res.status}`;
            try {
                const body = await res.json() as { message?: string; error?: string };
                msg = body.message ?? body.error ?? msg;
            } catch {
                // keep status
            }
            responses[key] = { status: 'error', data: null, error: msg };
            return;
        }

        const data = await res.json() as SnipQueueResponse;
        responses[key] = { status: 'loaded', data, error: null };
        if (data.counts) latestCounts.value = data.counts;
    } catch (e) {
        responses[key] = {
            status: 'error',
            data: null,
            error: e instanceof Error ? e.message : 'fetch failed',
        };
    }
}

function refresh(): void {
    delete responses[cacheKey.value];
    ensureLoaded();
}

function toggleRow(item: SnipQueueItem): void {
    const id = item.id || `${item.name}|${item.queue}`;
    if (expanded.value.has(id)) {
        expanded.value.delete(id);
    } else {
        expanded.value.add(id);
    }
    expanded.value = new Set(expanded.value);
}

function isExpanded(item: SnipQueueItem): boolean {
    const id = item.id || `${item.name}|${item.queue}`;
    return expanded.value.has(id);
}

function stateLabel(s: SnipQueueState): string {
    return { all: 'All', failed: 'Failed', pending: 'Pending', scheduled: 'Scheduled', completed: 'Completed' }[s];
}

function badgeFor(s: SnipQueueState): string {
    if (s === 'all') {
        const c = latestCounts.value;
        if (!c) return '';
        const sum = (c.failed ?? 0) + (c.pending ?? 0) + (c.scheduled ?? 0) + (c.completed ?? 0);
        return sum > 0 ? String(sum) : '';
    }
    const v = latestCounts.value?.[s as keyof SnipQueueCounts];
    return v === null || v === undefined ? '' : String(v);
}

function formatTimestamp(iso: string | null | undefined): string {
    if (!iso) return '';
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return iso;
    return date.toLocaleString();
}

async function copyText(event: MouseEvent, text: string): Promise<void> {
    event.stopPropagation();
    try {
        await navigator.clipboard.writeText(text);
    } catch {
        // silent
    }
}

function prevPage(): void {
    if (page.value > 1) page.value--;
}

function nextPage(): void {
    if (page.value < pageCount.value) page.value++;
}
</script>

<template>
    <div class="wrap">
        <header class="head">
            <nav class="states">
                <button
                    v-for="s in ALL_STATES"
                    :key="s"
                    type="button"
                    class="state-btn"
                    :class="{ 'state-btn--active': state === s, 'state-btn--disabled': stateDisabled[s] }"
                    :disabled="stateDisabled[s]"
                    :title="stateDisabled[s] ? (s === 'completed' ? 'Requires Laravel Horizon' : 'Driver does not support listing') : ''"
                    @click="state = s"
                >
                    {{ stateLabel(s) }}
                    <span v-if="badgeFor(s)" class="state-badge" :class="`state-badge--${s}`">{{ badgeFor(s) }}</span>
                </button>
            </nav>

            <div class="controls">
                <input
                    v-model="search"
                    type="search"
                    class="filter"
                    placeholder="Search by job class name…"
                />
                <label v-if="(state === 'completed' || state === 'all') && horizonAvailable" class="silenced-toggle" title="Include silenced jobs in results">
                    <input type="checkbox" v-model="includeSilenced" />
                    <span>incl. silenced</span>
                </label>
                <button type="button" class="sort" @click="refresh" title="Refresh">↻</button>
            </div>

            <div class="meta">
                <span class="driver-chip">{{ driver ?? 'unknown' }}</span>
                <span class="muted" v-if="current.status === 'loaded'">
                    {{ total }} match{{ total === 1 ? '' : 'es' }}<span v-if="total > perPage"> · page {{ page }}/{{ pageCount }}</span>
                </span>
            </div>
        </header>

        <p v-if="message" class="notice">{{ message }}</p>

        <div v-if="current.status === 'loading'" class="empty">Loading…</div>
        <div v-else-if="current.status === 'error'" class="error">Failed to load: {{ current.error }}</div>
        <div v-else-if="!supported && items.length === 0" class="empty">{{ message ?? 'Not supported on this driver.' }}</div>
        <div v-else-if="items.length === 0" class="empty">
            <template v-if="search">No jobs match "{{ search }}".</template>
            <template v-else>No {{ state }} jobs.</template>
        </div>

        <ul v-else class="list">
            <li
                v-for="item in items"
                :key="(item.id || '') + ':' + item.name + ':' + item.queue"
                class="row"
                :class="{ 'row--expanded': isExpanded(item) }"
            >
                <div class="row__head" @click="toggleRow(item)">
                    <span class="caret" :class="{ 'caret--open': isExpanded(item) }">▸</span>
                    <span v-if="state === 'all' && item.state" class="chip chip--state" :class="`chip--state-${item.state}`">{{ item.state }}</span>
                    <span class="job-name" :title="item.name">{{ item.name }}</span>
                    <span class="spacer" />
                    <span v-if="item.silenced" class="chip chip--silenced" title="Silenced job (Horizon)">silenced</span>
                    <span v-if="item.attempts > 0" class="chip chip--attempts" :title="`Attempts: ${item.attempts}`">×{{ item.attempts }}</span>
                    <span class="chip chip--queue" :title="`Queue: ${item.queue}`">{{ item.queue }}</span>
                    <button
                        v-if="item.id"
                        class="copy-btn"
                        type="button"
                        :title="'Copy job id'"
                        @click="copyText($event, item.id)"
                    >copy id</button>
                </div>

                <div v-if="isExpanded(item)" class="row__body">
                    <dl class="meta-grid">
                        <template v-if="item.failed_at"><dt>failed at</dt><dd>{{ formatTimestamp(item.failed_at) }}</dd></template>
                        <template v-if="item.available_at"><dt>available at</dt><dd>{{ formatTimestamp(item.available_at) }}</dd></template>
                        <template v-if="item.created_at"><dt>created at</dt><dd>{{ formatTimestamp(item.created_at) }}</dd></template>
                        <template v-if="item.reserved_at"><dt>reserved at</dt><dd>{{ formatTimestamp(item.reserved_at) }}</dd></template>
                        <template v-if="item.completed_at"><dt>completed at</dt><dd>{{ formatTimestamp(item.completed_at) }}</dd></template>
                        <template v-if="item.connection"><dt>connection</dt><dd>{{ item.connection }}</dd></template>
                    </dl>

                    <div v-if="item.exception_full" class="exception">
                        <header class="exception__head">
                            <strong>Exception</strong>
                            <button class="copy-btn" type="button" @click="copyText($event, item.exception_full!)">copy</button>
                        </header>
                        <pre class="exception__body">{{ item.exception_full }}</pre>
                    </div>

                    <div class="payload">
                        <header class="payload__head"><strong>Payload</strong></header>
                        <ValueTree :node="item.payload" :initially-open="true" :depth="0" />
                    </div>
                </div>
            </li>
        </ul>

        <footer v-if="pageCount > 1 && current.status === 'loaded'" class="pager">
            <button type="button" class="sort" :disabled="page <= 1" @click="prevPage">← prev</button>
            <span class="muted">page {{ page }} of {{ pageCount }}</span>
            <button type="button" class="sort" :disabled="page >= pageCount" @click="nextPage">next →</button>
        </footer>
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
    flex-direction: column;
    gap: 10px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--snip-border);
}

.states {
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
}

.state-btn {
    background: var(--snip-surface);
    border: 1px solid var(--snip-border);
    color: var(--snip-text-muted);
    padding: 4px 12px;
    font-size: 11px;
    font-weight: 600;
    border-radius: 6px;
    cursor: pointer;
    font-family: inherit;
    text-transform: capitalize;
}

.state-btn:hover:not(:disabled) {
    background: var(--snip-surface-2);
    color: var(--snip-text);
}

.state-btn--active {
    background: var(--snip-accent-strong);
    border-color: var(--snip-accent-strong-2);
    color: #ffffff;
}

.state-btn--disabled {
    opacity: 0.45;
    cursor: not-allowed;
}

.state-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.state-badge {
    background: var(--snip-chip-bg);
    color: inherit;
    padding: 0 6px;
    border-radius: 9999px;
    font-size: 10px;
    font-weight: 700;
    line-height: 1.4;
    min-width: 18px;
    text-align: center;
    font-variant-numeric: tabular-nums;
    border: 1px solid transparent;
}

.state-badge--all      { background: var(--snip-accent); color: #ffffff; }
.state-badge--failed   { background: var(--snip-bad); color: #ffffff; }
.state-badge--pending  { background: var(--snip-accent-strong); color: #ffffff; }
.state-badge--scheduled{ background: var(--snip-warn); color: #000000; }
.state-badge--completed{ background: var(--snip-ok); color: #000000; }

.state-btn--active .state-badge {
    background: #ffffff;
    color: var(--snip-accent-strong);
    border-color: rgba(255, 255, 255, 0.6);
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

.sort:hover:not(:disabled) {
    background: var(--snip-surface-2);
}

.sort:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}

.meta {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    font-size: 11px;
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

.notice {
    margin: 0;
    color: var(--snip-text-muted);
    font-size: 11px;
    padding: 6px 10px;
    background: var(--snip-surface);
    border: 1px solid var(--snip-border);
    border-radius: 6px;
}

.empty {
    color: var(--snip-text-faint);
    text-align: center;
    padding: 28px 0;
    font-size: 13px;
}

.error {
    color: var(--snip-bad);
    text-align: center;
    padding: 18px 0;
    font-size: 13px;
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
    cursor: pointer;
}

.row:hover .row__head {
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

.job-name {
    color: var(--snip-text-strong);
    font-weight: 600;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 12px;
    word-break: break-all;
}

.spacer {
    flex: 1;
}

.chip {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 10px;
    padding: 1px 6px;
    border-radius: 4px;
    background: var(--snip-chip-bg);
    color: var(--snip-text-faint);
    flex-shrink: 0;
}

.chip--attempts {
    background: var(--snip-bad);
    color: #ffffff;
}

.chip--queue {
    background: var(--snip-surface-3);
    color: var(--snip-text);
}

.chip--silenced {
    background: var(--snip-warn);
    color: #000000;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.chip--state {
    text-transform: uppercase;
    letter-spacing: 0.04em;
    font-size: 9px;
    font-weight: 700;
    padding: 1px 6px;
    border-radius: 4px;
}

.chip.chip--state-failed    { background: var(--snip-bad); color: #ffffff; }
.chip.chip--state-pending   { background: var(--snip-accent-strong); color: #ffffff; }
.chip.chip--state-scheduled { background: var(--snip-warn); color: #000000; }
.chip.chip--state-completed { background: var(--snip-ok); color: #000000; }

.silenced-toggle {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: var(--snip-surface);
    border: 1px solid var(--snip-border);
    color: var(--snip-text-muted);
    border-radius: 6px;
    padding: 4px 10px;
    font-size: 11px;
    cursor: pointer;
    user-select: none;
    white-space: nowrap;
}

.silenced-toggle input {
    margin: 0;
    accent-color: var(--snip-accent);
    cursor: pointer;
}

.silenced-toggle:hover {
    background: var(--snip-surface-2);
    color: var(--snip-text);
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

.row__body {
    padding: 10px 12px 12px 32px;
    border-top: 1px solid var(--snip-border);
    background: var(--snip-bg);
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.meta-grid {
    display: grid;
    grid-template-columns: 110px 1fr;
    gap: 4px 12px;
    margin: 0;
    font-size: 11px;
}

.meta-grid dt {
    color: var(--snip-text-faint);
    text-transform: uppercase;
    letter-spacing: 0.04em;
    font-size: 10px;
}

.meta-grid dd {
    margin: 0;
    color: var(--snip-text);
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    word-break: break-all;
}

.exception {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.exception__head,
.payload__head {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 11px;
    color: var(--snip-text-muted);
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.exception__body {
    background: var(--snip-surface);
    border: 1px solid var(--snip-border);
    border-radius: 6px;
    padding: 8px 10px;
    font-size: 11px;
    color: var(--snip-text);
    white-space: pre-wrap;
    word-break: break-word;
    max-height: 240px;
    overflow: auto;
    margin: 0;
}

.payload {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.pager {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    padding-top: 8px;
    border-top: 1px solid var(--snip-border);
}
</style>
