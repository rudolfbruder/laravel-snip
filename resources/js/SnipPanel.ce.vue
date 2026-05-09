<script setup lang="ts">
import { computed, getCurrentInstance, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ValueTree from './ValueTree.vue';
import TimingList from './TimingList.vue';
import MilestoneList from './MilestoneList.vue';
import DataLayerList from './DataLayerList.vue';
import type { DataLayerEvent, SnipEntry, SnipMilestone, SnipPayload, SnipTiming } from './types';
import { formatBytes, shortenFile } from './format';
import { installDataLayerHook, isCustomEvent } from './dataLayer';
import { readStorage, writeStorage } from './storage';
import { useTheme } from './theme';

type Tab = 'snips' | 'timings' | 'milestones' | 'datalayer';

const TAB_STORAGE_KEY = 'laravel-snip:tab';
const ALL_TABS: Tab[] = ['snips', 'timings', 'milestones', 'datalayer'];

const open = ref(false);
const dataLayerEnabled = ref<boolean>(true);
const entries = ref<SnipEntry[]>([]);
const timings = ref<SnipTiming[]>([]);
const milestones = ref<SnipMilestone[]>([]);
const dataLayerEvents = ref<DataLayerEvent[]>([]);
const activeIndex = ref(0);
const tab = ref<Tab>(readStorage(TAB_STORAGE_KEY, ALL_TABS) ?? 'snips');

const visibleTabs = computed<Tab[]>(() =>
    dataLayerEnabled.value ? ALL_TABS : ALL_TABS.filter((t) => t !== 'datalayer'),
);

const { mode: themeMode, resolved: resolvedTheme, cycle: cycleTheme } = useTheme();

let host: HTMLElement | undefined;
let uninstallDataLayerHook: (() => void) | null = null;

onMounted(() => {
    host = resolveHost();
    if (!host) return;

    loadPayloadInto(host);
    pickInitialTab();

    if (dataLayerEnabled.value) {
        uninstallDataLayerHook = installDataLayerHook(dataLayerEvents);
        pickInitialTab(); // re-check now that dataLayer events may have arrived
    }

    applyThemeAttribute();
    document.addEventListener('keydown', onKeydown);
});

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown);
    uninstallDataLayerHook?.();
    uninstallDataLayerHook = null;
});

watch(tab, (next) => writeStorage(TAB_STORAGE_KEY, next));
watch(resolvedTheme, applyThemeAttribute);

function resolveHost(): HTMLElement | undefined {
    return getCurrentInstance()?.proxy?.$el?.parentNode?.host as HTMLElement | undefined;
}

function loadPayloadInto(host: HTMLElement): void {
    const raw = host.getAttribute('data-payload') ?? '';
    if (raw === '') return;

    try {
        const parsed = JSON.parse(raw) as SnipPayload | SnipEntry[];

        if (Array.isArray(parsed)) {
            // v1 backwards-compat — flat snip array
            entries.value = parsed;
            return;
        }

        entries.value = parsed.snips ?? [];
        timings.value = parsed.timings ?? [];
        milestones.value = parsed.milestones ?? [];
        dataLayerEnabled.value = parsed.config?.datalayer ?? true;

        if (!dataLayerEnabled.value && tab.value === 'datalayer') {
            tab.value = 'snips';
        }
    } catch (e) {
        console.error('[laravel-snip] failed to parse payload', e);
    }
}

function pickInitialTab(): void {
    if (readStorage(TAB_STORAGE_KEY, ALL_TABS)) return; // honour persisted choice

    const counts: Array<[Tab, number]> = [
        ['snips', entries.value.length],
        ['timings', timings.value.length],
        ['milestones', milestones.value.length],
        ['datalayer', dataLayerEnabled.value ? dataLayerEvents.value.filter((e) => isCustomEvent(e.payload)).length : 0],
    ];

    const nonEmpty = counts.filter(([, n]) => n > 0);
    if (nonEmpty.length === 1) tab.value = nonEmpty[0][0];
}

function applyThemeAttribute(): void {
    host?.setAttribute('data-theme', resolvedTheme.value);
}

function onKeydown(event: KeyboardEvent): void {
    if (!isToggleCombo(event)) return;

    event.preventDefault();
    event.stopPropagation();
    open.value = !open.value;
}

function isToggleCombo(event: KeyboardEvent): boolean {
    return (event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k';
}

const themeIcon = computed<string>(() => {
    if (themeMode.value === 'light') return '☀';
    if (themeMode.value === 'dark') return '☾';
    return '◐';
});

const themeLabel = computed<string>(() => `Theme: ${themeMode.value}`);

const activeEntry = computed<SnipEntry | null>(() => entries.value[activeIndex.value] ?? null);

const customDataLayerCount = computed<number>(
    () => dataLayerEnabled.value ? dataLayerEvents.value.filter((e) => isCustomEvent(e.payload)).length : 0,
);

const totalCount = computed<number>(
    () => entries.value.length + timings.value.length + milestones.value.length + customDataLayerCount.value,
);

const hasContent = computed<boolean>(() => totalCount.value > 0);

const TAB_LABELS: Record<Tab, string> = {
    snips: 'Snips',
    timings: 'Timings',
    milestones: 'Milestones',
    datalayer: 'DataLayer',
};

function tabLabel(t: Tab): string {
    return TAB_LABELS[t];
}

function tabCount(t: Tab): number {
    return {
        snips: entries.value.length,
        timings: timings.value.length,
        milestones: milestones.value.length,
        datalayer: customDataLayerCount.value,
    }[t];
}

const copyState = ref<'idle' | 'copied' | 'failed'>('idle');
let copyResetTimer: number | null = null;

async function copyActiveEntry(): Promise<void> {
    if (!activeEntry.value) return;

    try {
        const json = JSON.stringify(activeEntry.value, null, 2);
        await navigator.clipboard.writeText(json);
        flashCopyState('copied');
    } catch {
        flashCopyState('failed');
    }
}

function flashCopyState(state: 'copied' | 'failed'): void {
    copyState.value = state;
    if (copyResetTimer !== null) clearTimeout(copyResetTimer);
    copyResetTimer = window.setTimeout(() => (copyState.value = 'idle'), 1500);
}
</script>

<template>
    <div v-if="hasContent" class="snip-root">
        <button
            class="toggle"
            :class="{ 'toggle--active': open }"
            type="button"
            :title="open ? 'Close (Cmd+K)' : 'Open (Cmd+K)'"
            @click="open = !open"
        >
            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="6" cy="6" r="3" />
                <circle cx="6" cy="18" r="3" />
                <line x1="20" y1="4" x2="8.12" y2="15.88" />
                <line x1="14.47" y1="14.48" x2="20" y2="20" />
                <line x1="8.12" y1="8.12" x2="12" y2="12" />
            </svg>
            <span class="label">snip</span>
            <span class="count">{{ totalCount }}</span>
        </button>

        <div v-if="open" class="panel" role="dialog" aria-label="Laravel Snip">
            <header class="panel__header">
                <svg class="icon icon--header" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="6" cy="6" r="3" />
                    <circle cx="6" cy="18" r="3" />
                    <line x1="20" y1="4" x2="8.12" y2="15.88" />
                    <line x1="14.47" y1="14.48" x2="20" y2="20" />
                    <line x1="8.12" y1="8.12" x2="12" y2="12" />
                </svg>
                <strong>Laravel Snip</strong>

                <nav class="tabs">
                    <button
                        v-for="t in visibleTabs"
                        :key="t"
                        type="button"
                        class="tab"
                        :class="{ 'tab--active': tab === t }"
                        @click="tab = t"
                    >
                        {{ tabLabel(t) }} <span class="tab__badge">{{ tabCount(t) }}</span>
                    </button>
                </nav>

                <button
                    class="theme"
                    type="button"
                    :title="themeLabel"
                    :aria-label="themeLabel"
                    @click="cycleTheme"
                >
                    {{ themeIcon }}
                </button>
                <button class="close" type="button" aria-label="Close" @click="open = false">×</button>
            </header>

            <section v-if="tab === 'snips'" class="panel__body">
                <aside class="entries">
                    <button
                        v-for="(entry, index) in entries"
                        :key="index"
                        class="entry"
                        :class="{ 'entry--active': index === activeIndex }"
                        type="button"
                        @click="activeIndex = index"
                    >
                        <span class="entry__label">{{ entry.label || `#${index + 1}` }}</span>
                        <span class="entry__meta">
                            {{ shortenFile(entry.file) }}<span v-if="entry.line">:{{ entry.line }}</span>
                        </span>
                        <span class="entry__time">
                            {{ entry.time_ms }} ms
                            <span v-if="entry.bytes !== null" class="entry__bytes">· {{ formatBytes(entry.bytes) }}</span>
                        </span>
                    </button>
                    <div v-if="entries.length === 0" class="empty">No snips recorded.</div>
                </aside>

                <article class="value">
                    <div v-if="activeEntry" class="value__inner">
                        <header class="value__header">
                            <strong v-if="activeEntry.label">{{ activeEntry.label }}</strong>
                            <span class="muted">
                                {{ shortenFile(activeEntry.file) }}<span v-if="activeEntry.line">:{{ activeEntry.line }}</span>
                                · {{ activeEntry.time_ms }} ms
                                <span v-if="activeEntry.bytes !== null">· {{ formatBytes(activeEntry.bytes) }}</span>
                            </span>
                            <button
                                class="copy"
                                type="button"
                                :title="copyState === 'copied' ? 'Copied!' : copyState === 'failed' ? 'Copy failed' : 'Copy as JSON'"
                                @click="copyActiveEntry"
                            >
                                {{ copyState === 'copied' ? '✓ copied' : copyState === 'failed' ? '✕ failed' : 'copy' }}
                            </button>
                        </header>
                        <ValueTree :node="activeEntry.value" :initially-open="true" :depth="0" />
                    </div>
                </article>
            </section>

            <section v-else-if="tab === 'timings'" class="panel__scroll">
                <TimingList :timings="timings" />
            </section>

            <section v-else-if="tab === 'milestones'" class="panel__scroll">
                <MilestoneList :milestones="milestones" />
            </section>

            <section v-else class="panel__scroll">
                <DataLayerList :events="dataLayerEvents" />
            </section>
        </div>
    </div>
</template>


<style>
:host {
    /* Default = dark theme. Light overrides come after. */
    --snip-bg: #0b1220;
    --snip-surface: #0f172a;
    --snip-surface-2: #111827;
    --snip-surface-3: #1e293b;
    --snip-border: #1f2937;
    --snip-border-soft: rgba(255, 255, 255, 0.08);
    --snip-text: #e5e7eb;
    --snip-text-strong: #f9fafb;
    --snip-text-muted: #94a3b8;
    --snip-text-faint: #64748b;
    --snip-text-fainter: #475569;
    --snip-accent: #60a5fa;
    --snip-accent-strong: #2563eb;
    --snip-accent-strong-2: #1d4ed8;
    --snip-success: #34d399;
    --snip-shadow: 0 24px 60px rgba(0, 0, 0, 0.5);
    --snip-shadow-pill: 0 12px 28px rgba(0, 0, 0, 0.35);
    --snip-hover: rgba(255, 255, 255, 0.04);
    --snip-chip-bg: rgba(255, 255, 255, 0.10);
    --snip-ok: #34d399;
    --snip-warn: #fbbf24;
    --snip-bad: #f87171;
    color-scheme: dark;
}

:host([data-theme="light"]) {
    --snip-bg: #ffffff;
    --snip-surface: #f8fafc;
    --snip-surface-2: #eef2f7;
    --snip-surface-3: #e2e8f0;
    --snip-border: #cbd5e1;
    --snip-border-soft: rgba(15, 23, 42, 0.08);
    --snip-text: #1f2937;
    --snip-text-strong: #0f172a;
    --snip-text-muted: #475569;
    --snip-text-faint: #64748b;
    --snip-text-fainter: #94a3b8;
    --snip-accent: #2563eb;
    --snip-accent-strong: #1d4ed8;
    --snip-accent-strong-2: #1e40af;
    --snip-shadow: 0 18px 40px rgba(15, 23, 42, 0.18);
    --snip-shadow-pill: 0 8px 22px rgba(15, 23, 42, 0.18);
    --snip-hover: rgba(15, 23, 42, 0.04);
    --snip-chip-bg: rgba(15, 23, 42, 0.08);
    color-scheme: light;
}

:host,
.snip-root {
    all: initial;
    color: var(--snip-text);
    font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    font-size: 13px;
}

.snip-root {
    position: fixed;
    bottom: 16px;
    right: 16px;
    z-index: 2147483647;
}

.toggle {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: var(--snip-surface-2);
    color: var(--snip-text);
    border: 1px solid var(--snip-border);
    border-radius: 9999px;
    padding: 8px 14px;
    cursor: pointer;
    box-shadow: var(--snip-shadow-pill);
    transition: transform 0.12s ease, background 0.12s ease;
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.toggle:hover {
    background: var(--snip-surface-3);
    transform: translateY(-1px);
}

.toggle--active {
    background: var(--snip-accent-strong);
    border-color: var(--snip-accent-strong-2);
    color: #ffffff;
}

.icon {
    width: 14px;
    height: 14px;
    color: var(--snip-success);
    flex-shrink: 0;
}

.icon--header {
    color: var(--snip-accent);
    width: 16px;
    height: 16px;
}

.label {
    color: var(--snip-text-strong);
}

.toggle--active .label,
.toggle--active .icon,
.toggle--active .count {
    color: #ffffff;
}

.count {
    background: var(--snip-chip-bg);
    color: var(--snip-text-strong);
    border-radius: 9999px;
    padding: 1px 8px;
    font-size: 11px;
    line-height: 1.4;
}

.panel {
    position: absolute;
    bottom: 48px;
    right: 0;
    width: min(880px, 92vw);
    height: min(560px, 70vh);
    background: var(--snip-bg);
    border: 1px solid var(--snip-border);
    border-radius: 12px;
    box-shadow: var(--snip-shadow);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.panel__header {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 12px 16px;
    background: var(--snip-surface);
    border-bottom: 1px solid var(--snip-border);
}

.panel__header strong {
    color: var(--snip-text-strong);
}

.tabs {
    display: flex;
    gap: 4px;
    margin-left: 8px;
}

.tab {
    background: transparent;
    border: 1px solid transparent;
    color: var(--snip-text-muted);
    padding: 4px 12px;
    font-size: 12px;
    font-weight: 600;
    border-radius: 6px;
    cursor: pointer;
    font-family: inherit;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.tab:hover {
    background: var(--snip-hover);
    color: var(--snip-text);
}

.tab--active {
    background: var(--snip-surface-3);
    border-color: var(--snip-border);
    color: var(--snip-text-strong);
}

.tab__badge {
    background: var(--snip-chip-bg);
    color: inherit;
    padding: 0 6px;
    border-radius: 9999px;
    font-size: 10px;
    font-weight: 600;
    line-height: 1.4;
    min-width: 18px;
    text-align: center;
}

.muted {
    color: var(--snip-text-muted);
    font-size: 12px;
}

.theme {
    margin-left: auto;
    background: transparent;
    color: var(--snip-text-muted);
    border: 1px solid var(--snip-border);
    border-radius: 6px;
    padding: 2px 10px;
    font-size: 14px;
    line-height: 1;
    cursor: pointer;
}

.theme:hover {
    color: var(--snip-text);
    background: var(--snip-hover);
}

.close {
    background: transparent;
    color: var(--snip-text-muted);
    border: none;
    font-size: 22px;
    line-height: 1;
    cursor: pointer;
    padding: 0 4px;
}

.close:hover {
    color: var(--snip-text-strong);
}

.panel__body {
    display: grid;
    grid-template-columns: 240px 1fr;
    flex: 1;
    min-height: 0;
    background: var(--snip-bg);
}

.panel__scroll {
    flex: 1;
    overflow-y: auto;
    padding: 14px 18px;
    background: var(--snip-bg);
}

.entries {
    border-right: 1px solid var(--snip-border);
    overflow-y: auto;
    background: var(--snip-surface);
}

.empty {
    color: var(--snip-text-faint);
    text-align: center;
    padding: 28px 14px;
    font-size: 12px;
}

.entry {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 2px;
    width: 100%;
    padding: 10px 14px;
    background: transparent;
    border: none;
    border-bottom: 1px solid var(--snip-border);
    color: var(--snip-text);
    text-align: left;
    cursor: pointer;
    font-family: inherit;
    font-size: 12px;
}

.entry:hover {
    background: var(--snip-surface-2);
}

.entry--active {
    background: var(--snip-surface-3);
}

.entry__label {
    font-weight: 600;
    color: var(--snip-text-strong);
    word-break: break-all;
}

.entry__meta {
    color: var(--snip-text-faint);
    font-size: 11px;
    word-break: break-all;
}

.entry__time {
    color: var(--snip-text-fainter);
    font-size: 10px;
}

.entry__bytes {
    margin-left: 4px;
    color: var(--snip-text-faint);
}

.value {
    overflow: auto;
    padding: 14px 18px;
    background: var(--snip-bg);
}

.value__inner {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.value__header {
    display: flex;
    align-items: center;
    gap: 10px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--snip-border);
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
</style>
