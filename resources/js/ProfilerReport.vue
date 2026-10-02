<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useClipboard } from '@vueuse/core';
import type { SnipProfile, SnipProfileCounter } from './types';
import { durationColor, formatBytes } from './format';
import { readStorage, writeStorage } from './storage';

const props = defineProps<{
    profile: SnipProfile;
}>();

type View = 'structured' | 'raw';

const VIEW_STORAGE_KEY = 'laravel-snip:profiler-view';
const VIEWS: View[] = ['structured', 'raw'];

const view = ref<View>(readStorage(VIEW_STORAGE_KEY, VIEWS) ?? 'structured');
watch(view, (next) => writeStorage(VIEW_STORAGE_KEY, next));

const { copy, copied, isSupported } = useClipboard({ legacy: true, copiedDuring: 1500 });

const callKind = ref<string | null>(null);

const contextEntries = computed<Array<[string, string]>>(() =>
    Object.entries(props.profile.context).map(([key, value]) => [key, typeof value === 'string' ? value : JSON.stringify(value)]),
);

const measuredPhases = computed(() => props.profile.phases.filter((p) => p.ms !== null && p.ms > 0));

const timelineMs = computed<number>(() => Math.max(props.profile.total_ms, 1));

const callKinds = computed<Array<{ kind: string; count: number }>>(() => {
    const counts = new Map<string, number>();
    for (const call of props.profile.calls) counts.set(call.kind, (counts.get(call.kind) ?? 0) + 1);
    return [...counts].map(([kind, count]) => ({ kind, count }));
});

const visibleCalls = computed(() =>
    callKind.value === null ? props.profile.calls : props.profile.calls.filter((c) => c.kind === callKind.value),
);

const heaviestViewMs = computed<number>(() => Math.max(...props.profile.views.heaviest.map((v) => v.ms), 1));

watch(() => props.profile, () => {
    if (callKind.value !== null && !callKinds.value.some((k) => k.kind === callKind.value)) callKind.value = null;
});

function ms(value: number | null, digits = 1): string {
    return value === null ? '?' : `${value.toFixed(digits)} ms`;
}

function counter(c: SnipProfileCounter): string {
    return `${c.kind} ${c.count} · ${Math.round(c.ms)} ms`;
}

function statusTone(status: number): string {
    if (status >= 500) return 'bad';
    if (status >= 400) return 'warn';
    if (status >= 300) return 'info';
    return 'ok';
}

function isFailed(extra: string): boolean {
    return /fail/i.test(extra);
}

function phaseSlot(index: number): string {
    return `var(--series-${(index % 6) + 1})`;
}

function waterfall(startMs: number, durationMs: number): Record<string, string> {
    return {
        left: `${Math.min((startMs / timelineMs.value) * 100, 100)}%`,
        width: `max(2px, ${(durationMs / timelineMs.value) * 100}%)`,
        background: durationColor(durationMs),
    };
}
</script>

<template>
    <div class="wrap">
        <header class="toolbar">
            <div class="seg" role="tablist" aria-label="Profiler view">
                <button
                    v-for="v in VIEWS"
                    :key="v"
                    type="button"
                    role="tab"
                    class="seg__btn"
                    :class="{ 'seg__btn--active': view === v }"
                    :aria-selected="view === v"
                    @click="view = v"
                >
                    {{ v === 'structured' ? 'Structured' : 'Raw' }}
                </button>
            </div>
            <button
                v-if="isSupported"
                class="copy"
                type="button"
                :title="copied ? 'Copied!' : 'Copy the raw report'"
                @click="copy(profile.text)"
            >
                {{ copied ? '✓ copied' : 'copy' }}
            </button>
        </header>

        <pre v-if="view === 'raw'" class="raw">{{ profile.text }}</pre>

        <template v-else>
            <section class="card summary">
                <div class="summary__request">
                    <span v-if="profile.surface" class="surface">{{ profile.surface }}</span>
                    <span class="method">{{ profile.method }}</span>
                    <span class="url" :title="profile.url">{{ profile.url }}</span>
                    <span v-if="profile.response" class="status" :class="`status--${statusTone(profile.response.status)}`">
                        {{ profile.response.status }}
                    </span>
                </div>
                <dl class="stats">
                    <div class="stat">
                        <dt>total</dt>
                        <dd class="stat__hero">{{ Math.round(profile.total_ms) }}<small> ms</small></dd>
                    </div>
                    <div class="stat">
                        <dt>peak memory</dt>
                        <dd>{{ profile.peak_memory_mb.toFixed(1) }}<small> MB</small></dd>
                    </div>
                    <div v-if="profile.response" class="stat">
                        <dt>response</dt>
                        <dd>{{ profile.response.bytes === null ? 'streamed' : formatBytes(profile.response.bytes) }}</dd>
                    </div>
                </dl>
                <ul v-if="contextEntries.length" class="context">
                    <li v-for="[key, value] in contextEntries" :key="key" class="context__item">
                        <span class="context__key">{{ key }}</span>
                        <span class="context__value">{{ value }}</span>
                    </li>
                </ul>
            </section>

            <section class="card">
                <h3 class="card__title">Phases</h3>
                <div class="phases" role="img" :aria-label="profile.phases.map((p) => `${p.label} ${ms(p.ms, 0)}`).join(', ')">
                    <span
                        v-for="phase in measuredPhases"
                        :key="phase.label"
                        class="phases__seg"
                        :style="{ flexGrow: phase.ms ?? 0, background: phaseSlot(profile.phases.indexOf(phase)) }"
                        :title="`${phase.label}: ${ms(phase.ms, 0)}`"
                    />
                </div>
                <ul class="legend">
                    <li v-for="(phase, i) in profile.phases" :key="phase.label" class="legend__item">
                        <span class="legend__swatch" :style="{ background: phaseSlot(i) }" />
                        <span class="legend__label">{{ phase.label }}</span>
                        <span class="legend__ms">{{ ms(phase.ms, 0) }}</span>
                    </li>
                </ul>
                <ul class="chips">
                    <li v-for="t in profile.totals" :key="t.kind" class="chip">{{ counter(t) }}</li>
                    <li class="chip">cache hit {{ profile.cache.hit }} / miss {{ profile.cache.miss }} / write {{ profile.cache.write }}</li>
                </ul>
            </section>

            <details v-if="profile.steps.length" class="card" open>
                <summary class="card__title">Steps <span class="count">{{ profile.steps.length }}</span></summary>
                <ul class="rows">
                    <li v-for="(step, i) in profile.steps" :key="i" class="step">
                        <span class="at">+{{ Math.round(step.start_ms) }}</span>
                        <div class="step__main" :style="{ paddingLeft: `${step.depth * 14}px` }">
                            <span class="step__label">{{ step.label }}</span>
                            <span v-for="c in step.counters" :key="c.kind" class="chip chip--sm">{{ counter(c) }}</span>
                        </div>
                        <span class="track" aria-hidden="true"><span class="track__bar" :style="waterfall(step.start_ms, step.duration_ms)" /></span>
                        <span class="dur" :style="{ color: durationColor(step.duration_ms) }">{{ ms(step.duration_ms) }}</span>
                    </li>
                </ul>
            </details>

            <details v-if="profile.calls.length" class="card" open>
                <summary class="card__title">Calls <span class="count">{{ profile.calls.length }}</span></summary>
                <div v-if="callKinds.length > 1" class="filters">
                    <button type="button" class="filter" :class="{ 'filter--active': callKind === null }" @click="callKind = null">
                        all <span class="count">{{ profile.calls.length }}</span>
                    </button>
                    <button
                        v-for="k in callKinds"
                        :key="k.kind"
                        type="button"
                        class="filter"
                        :class="{ 'filter--active': callKind === k.kind }"
                        @click="callKind = k.kind"
                    >
                        {{ k.kind }} <span class="count">{{ k.count }}</span>
                    </button>
                </div>
                <ul class="rows">
                    <li v-for="(call, i) in visibleCalls" :key="i" class="call" :class="{ 'call--failed': isFailed(call.extra) }">
                        <span class="at">+{{ Math.round(call.at_ms) }}</span>
                        <span class="kind">{{ call.kind }}</span>
                        <div class="call__main">
                            <span class="mono">{{ call.label }}</span>
                            <span v-if="call.extra" class="call__extra">{{ call.extra }}</span>
                            <span v-if="call.step !== '-'" class="in">in {{ call.step }}</span>
                        </div>
                        <span class="dur" :style="{ color: durationColor(call.ms) }">{{ ms(call.ms) }}</span>
                    </li>
                </ul>
            </details>

            <details v-if="profile.views.count" class="card">
                <summary class="card__title">
                    Blade views <span class="count">{{ profile.views.count }}</span>
                    <span class="card__hint">first at +{{ Math.round(profile.views.first_at_ms ?? 0) }} ms · time until the next view starts</span>
                </summary>
                <ul class="rows">
                    <li v-for="v in profile.views.heaviest" :key="v.name" class="bar-row">
                        <span class="times">{{ v.count }}×</span>
                        <div class="bar-row__main">
                            <span class="mono">{{ v.name }}</span>
                            <span class="track" aria-hidden="true"><span class="track__bar" :style="{ width: `max(2px, ${(v.ms / heaviestViewMs) * 100}%)` }" /></span>
                        </div>
                        <span class="dur">{{ ms(v.ms) }}</span>
                    </li>
                </ul>
            </details>

            <details v-if="profile.redis_keys.length" class="card">
                <summary class="card__title">Redis keys read more than once <span class="count">{{ profile.redis_keys.length }}</span></summary>
                <ul class="rows">
                    <li v-for="k in profile.redis_keys" :key="k.key" class="bar-row">
                        <span class="times">{{ k.count }}×</span>
                        <span class="mono bar-row__main">{{ k.key }}</span>
                        <span class="dur">{{ ms(k.ms) }}</span>
                    </li>
                </ul>
            </details>

            <details v-if="profile.queries.count" class="card" open>
                <summary class="card__title">
                    SQL <span class="count">{{ profile.queries.count }}</span>
                    <span class="card__hint">slowest {{ profile.queries.slowest.length }}</span>
                </summary>

                <div v-if="profile.queries.repeated.length" class="n1">
                    <h4 class="n1__title">Repeated — possible N+1</h4>
                    <details v-for="q in profile.queries.repeated" :key="q.sql" class="sql sql--warn">
                        <summary class="sql__head">
                            <span class="times">{{ q.count }}×</span>
                            <span class="sql__preview">{{ q.sql }}</span>
                            <span class="dur">{{ ms(q.ms) }}</span>
                        </summary>
                        <pre class="sql__full">{{ q.sql }}</pre>
                    </details>
                </div>

                <details v-for="(q, i) in profile.queries.slowest" :key="i" class="sql">
                    <summary class="sql__head">
                        <span class="dur" :style="{ color: durationColor(q.ms) }">{{ ms(q.ms) }}</span>
                        <span class="sql__preview">{{ q.sql }}</span>
                        <span class="in">{{ q.connection }}<template v-if="q.step !== '-'"> · in {{ q.step }}</template></span>
                    </summary>
                    <pre class="sql__full">{{ q.sql }}</pre>
                </details>
            </details>
        </template>
    </div>
</template>

<style scoped>
.wrap {
    /* Phase identity: the first six slots of the categorical palette, in fixed order. */
    --series-1: #3987e5;
    --series-2: #d95926;
    --series-3: #199e70;
    --series-4: #c98500;
    --series-5: #d55181;
    --series-6: #008300;

    display: flex;
    flex-direction: column;
    gap: 10px;
    color: var(--snip-text);
    font-size: 12px;
}

:host([data-theme="light"]) .wrap {
    --series-1: #2a78d6;
    --series-2: #eb6834;
    --series-3: #1baf7a;
    --series-4: #eda100;
    --series-5: #e87ba4;
    --series-6: #008300;
}

.mono,
.raw,
.at,
.dur,
.times,
.kind,
.sql__preview,
.sql__full,
.url,
.method {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}

/* Toolbar */

.toolbar {
    display: flex;
    align-items: center;
    gap: 10px;
}

.seg {
    display: inline-flex;
    padding: 2px;
    gap: 2px;
    background: var(--snip-surface-2);
    border: 1px solid var(--snip-border);
    border-radius: 8px;
}

.seg__btn {
    background: transparent;
    border: 0;
    color: var(--snip-text-muted);
    border-radius: 6px;
    padding: 3px 12px;
    font: inherit;
    font-size: 11px;
    cursor: pointer;
}

.seg__btn:hover {
    color: var(--snip-text);
}

.seg__btn--active {
    background: var(--snip-surface-3);
    color: var(--snip-text-strong);
    font-weight: 600;
}

.copy {
    margin-left: auto;
    background: transparent;
    border: 1px solid var(--snip-border);
    color: var(--snip-text-muted);
    border-radius: 6px;
    padding: 3px 10px;
    font-size: 11px;
    font-family: inherit;
    cursor: pointer;
    flex-shrink: 0;
}

.copy:hover {
    background: var(--snip-surface-2);
    color: var(--snip-text);
}

.raw {
    margin: 0;
    padding: 12px 14px;
    background: var(--snip-surface);
    border: 1px solid var(--snip-border);
    border-radius: 8px;
    color: var(--snip-text);
    font-size: 11.5px;
    line-height: 1.55;
    white-space: pre;
    overflow-x: auto;
}

/* Cards */

.card {
    padding: 10px 12px;
    background: var(--snip-surface);
    border: 1px solid var(--snip-border);
    border-radius: 8px;
}

.card__title {
    display: flex;
    align-items: baseline;
    gap: 8px;
    margin: 0;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--snip-text-muted);
}

summary.card__title {
    cursor: pointer;
    list-style: none;
}

summary.card__title::-webkit-details-marker {
    display: none;
}

summary.card__title::before {
    content: '▸';
    color: var(--snip-text-faint);
    transition: transform 0.12s ease;
}

details[open] > summary.card__title::before {
    transform: rotate(90deg);
}

details[open] > summary.card__title {
    margin-bottom: 8px;
}

.card__hint {
    margin-left: auto;
    text-transform: none;
    letter-spacing: 0;
    font-weight: 400;
    color: var(--snip-text-faint);
}

.count {
    color: var(--snip-text-faint);
    font-weight: 400;
    font-variant-numeric: tabular-nums;
}

/* Summary */

.summary {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.summary__request {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
}

.surface {
    background: var(--snip-accent-strong);
    color: #fff;
    border-radius: 9999px;
    padding: 1px 9px;
    font-size: 11px;
    font-weight: 600;
    flex-shrink: 0;
}

.method {
    font-weight: 700;
    color: var(--snip-text-strong);
    flex-shrink: 0;
}

.url {
    color: var(--snip-text-muted);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    min-width: 0;
}

.status {
    margin-left: auto;
    flex-shrink: 0;
    padding: 1px 8px;
    border-radius: 9999px;
    font-weight: 700;
    font-size: 11px;
    background: var(--snip-chip-bg);
}

.status--ok { color: var(--snip-ok); }
.status--info { color: var(--snip-accent); }
.status--warn { color: var(--snip-warn); }
.status--bad { color: var(--snip-bad); }

.stats {
    display: flex;
    gap: 24px;
    margin: 0;
}

.stat dt {
    color: var(--snip-text-faint);
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.stat dd {
    margin: 2px 0 0;
    font-size: 15px;
    font-weight: 600;
    color: var(--snip-text-strong);
    font-variant-numeric: tabular-nums;
}

.stat dd small {
    font-size: 11px;
    font-weight: 400;
    color: var(--snip-text-muted);
}

.stat__hero {
    font-size: 22px !important;
}

.context {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin: 0;
    padding: 0;
    list-style: none;
}

.context__item {
    display: inline-flex;
    border: 1px solid var(--snip-border);
    border-radius: 6px;
    overflow: hidden;
    font-size: 11px;
}

.context__key {
    padding: 1px 6px;
    background: var(--snip-surface-2);
    color: var(--snip-text-faint);
}

.context__value {
    padding: 1px 6px;
    color: var(--snip-text);
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}

/* Phases */

.phases {
    display: flex;
    gap: 2px;
    height: 14px;
    margin-top: 8px;
}

.phases__seg {
    flex-basis: 0;
    min-width: 2px;
    border-radius: 4px;
}

.legend {
    display: flex;
    flex-wrap: wrap;
    gap: 4px 14px;
    margin: 8px 0 0;
    padding: 0;
    list-style: none;
}

.legend__item {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.legend__swatch {
    width: 8px;
    height: 8px;
    border-radius: 2px;
}

.legend__label {
    color: var(--snip-text-muted);
}

.legend__ms {
    color: var(--snip-text-strong);
    font-weight: 600;
    font-variant-numeric: tabular-nums;
}

.chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin: 10px 0 0;
    padding: 0;
    list-style: none;
}

.chip {
    background: var(--snip-chip-bg);
    color: var(--snip-text-muted);
    border-radius: 9999px;
    padding: 1px 8px;
    font-size: 11px;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}

.chip--sm {
    font-size: 10px;
    padding: 0 6px;
}

/* Rows shared by steps, calls, views, redis keys */

.rows {
    display: flex;
    flex-direction: column;
    margin: 0;
    padding: 0;
    list-style: none;
}

.rows > li {
    padding: 4px 0;
    border-top: 1px solid var(--snip-border-soft);
}

.rows > li:first-child {
    border-top: 0;
}

.at,
.times {
    color: var(--snip-text-faint);
    font-size: 11px;
    font-variant-numeric: tabular-nums;
    text-align: right;
}

.dur {
    font-size: 11px;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    text-align: right;
    white-space: nowrap;
    color: var(--snip-text-strong);
}

.in {
    color: var(--snip-text-faint);
    font-size: 11px;
}

.track {
    position: relative;
    display: block;
    height: 6px;
    background: var(--snip-surface-2);
    border-radius: 4px;
    overflow: hidden;
}

.track__bar {
    position: absolute;
    top: 0;
    bottom: 0;
    left: 0;
    border-radius: 4px;
    background: var(--snip-accent);
}

/* Steps: waterfall against the whole request */

.step {
    display: grid;
    grid-template-columns: 44px minmax(0, 1fr) 30% 64px;
    align-items: center;
    gap: 10px;
}

.step__main {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 4px 6px;
    min-width: 0;
}

.step__label {
    color: var(--snip-text-strong);
    font-weight: 600;
    word-break: break-word;
}

/* Calls */

.filters {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 6px;
}

.filter {
    background: transparent;
    border: 1px solid var(--snip-border);
    color: var(--snip-text-muted);
    border-radius: 9999px;
    padding: 1px 10px;
    font: inherit;
    font-size: 11px;
    cursor: pointer;
}

.filter--active {
    background: var(--snip-surface-3);
    color: var(--snip-text-strong);
    border-color: var(--snip-text-faint);
}

.call {
    display: grid;
    grid-template-columns: 44px 56px minmax(0, 1fr) 64px;
    align-items: baseline;
    gap: 10px;
}

.kind {
    font-size: 11px;
    color: var(--snip-accent);
}

.call__main {
    display: flex;
    flex-direction: column;
    gap: 1px;
    min-width: 0;
    word-break: break-all;
}

.call__extra {
    color: var(--snip-text-muted);
    font-size: 11px;
}

.call--failed .kind,
.call--failed .call__extra {
    color: var(--snip-bad);
    font-weight: 600;
}

.call--failed .call__extra::before {
    content: '✕ ';
}

/* Views and redis keys */

.bar-row {
    display: grid;
    grid-template-columns: 36px minmax(0, 1fr) 64px;
    align-items: center;
    gap: 10px;
}

.bar-row__main {
    display: flex;
    flex-direction: column;
    gap: 3px;
    min-width: 0;
    word-break: break-all;
    font-size: 11.5px;
}

/* SQL */

.n1 {
    margin-bottom: 8px;
    padding: 6px 8px;
    border: 1px solid var(--snip-warn);
    border-radius: 6px;
}

.n1__title {
    margin: 0 0 4px;
    font-size: 11px;
    color: var(--snip-warn);
}

.sql {
    border-top: 1px solid var(--snip-border-soft);
}

.sql:first-of-type {
    border-top: 0;
}

.sql__head {
    display: grid;
    grid-template-columns: 64px minmax(0, 1fr) auto;
    align-items: baseline;
    gap: 10px;
    padding: 4px 0;
    cursor: pointer;
    list-style: none;
}

.sql--warn .sql__head {
    grid-template-columns: 36px minmax(0, 1fr) 64px;
}

.sql__head::-webkit-details-marker {
    display: none;
}

.sql__preview {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 11.5px;
}

.sql[open] .sql__preview {
    color: var(--snip-text-faint);
}

.sql__full {
    margin: 0 0 6px;
    padding: 8px 10px;
    background: var(--snip-bg);
    border-radius: 6px;
    font-size: 11.5px;
    line-height: 1.5;
    white-space: pre-wrap;
    word-break: break-word;
}
</style>
