<script setup lang="ts">
import { computed, getCurrentInstance, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { onClickOutside, useDraggable } from '@vueuse/core';
import ValueTree from './ValueTree.vue';
import TimingList from './TimingList.vue';
import MilestoneList from './MilestoneList.vue';
import DataLayerList from './DataLayerList.vue';
import CacheList from './CacheList.vue';
import QueueList from './QueueList.vue';
import type { DataLayerEvent, SnipCache, SnipEntry, SnipMilestone, SnipPayload, SnipTiming } from './types';
import { formatBytes, shortenFile } from './format';
import { installDataLayerHook, isCustomEvent } from './dataLayer';
import { readStorage, writeStorage } from './storage';
import { useTheme } from './theme';

type Tab = 'snips' | 'timings' | 'milestones' | 'datalayer' | 'cache' | 'queue';

const TAB_STORAGE_KEY = 'laravel-snip:tab';
const SETTINGS_KEY = 'laravel-snip:settings';
const OPEN_STATE_KEY = 'laravel-snip:state:open';
const SIZE_STATE_KEY = 'laravel-snip:state:size';
const POS_STATE_KEY = 'laravel-snip:state:pos';
const ALL_TABS: Tab[] = ['snips', 'timings', 'milestones', 'datalayer', 'cache', 'queue'];

interface PersistSettings {
    persistOpen: boolean;
    persistSize: boolean;
    persistPos: boolean;
}

const DEFAULT_SETTINGS: PersistSettings = {
    persistOpen: false,
    persistSize: false,
    persistPos: false,
};

interface Size { w: number; h: number; }
interface Pos { x: number; y: number; }

function readJson<T>(key: string): T | null {
    try {
        if (typeof localStorage === 'undefined') return null;
        const raw = localStorage.getItem(key);
        if (raw === null) return null;
        return JSON.parse(raw) as T;
    } catch {
        return null;
    }
}

function writeJson(key: string, value: unknown): void {
    try {
        if (typeof localStorage !== 'undefined') localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // ignore
    }
}

const storedSettings = readJson<Partial<PersistSettings>>(SETTINGS_KEY);
const settings = ref<PersistSettings>({ ...DEFAULT_SETTINGS, ...(storedSettings ?? {}) });

const storedOpenInit = settings.value.persistOpen ? readJson<boolean>(OPEN_STATE_KEY) : null;
const storedSizeInit = settings.value.persistSize ? readJson<Size>(SIZE_STATE_KEY) : null;
const storedPosInit = settings.value.persistPos ? readJson<Pos>(POS_STATE_KEY) : null;

const open = ref<boolean>(storedOpenInit === true);
const settingsOpen = ref<boolean>(false);
const dataLayerEnabled = ref<boolean>(true);
const cacheEnabled = ref<boolean>(false);
const queueEnabled = ref<boolean>(false);
const queueUrl = ref<string | null>(null);
const queueDriver = ref<string | null>(null);
const queueSupportsListing = ref<boolean>(false);
const queueHorizon = ref<boolean>(false);
const entries = ref<SnipEntry[]>([]);
const timings = ref<SnipTiming[]>([]);
const milestones = ref<SnipMilestone[]>([]);
const dataLayerEvents = ref<DataLayerEvent[]>([]);
const cache = ref<SnipCache | null>(null);
const cacheValueUrl = ref<string | null>(null);
const activeIndex = ref(0);
const tab = ref<Tab>(readStorage(TAB_STORAGE_KEY, ALL_TABS) ?? 'snips');

const visibleTabs = computed<Tab[]>(() =>
    ALL_TABS.filter((t) => {
        if (t === 'datalayer') return dataLayerEnabled.value;
        if (t === 'cache') return cacheEnabled.value;
        if (t === 'queue') return queueEnabled.value;
        return true;
    }),
);

const { mode: themeMode, resolved: resolvedTheme } = useTheme();

const panelRef = ref<HTMLElement | null>(null);
const panelHandleRef = ref<HTMLElement | null>(null);
const settingsMenuRef = ref<HTMLElement | null>(null);

const panelMoved = ref<boolean>(storedPosInit !== null);

const {
    x: panelX,
    y: panelY,
    isDragging: panelDragging,
} = useDraggable(panelRef, {
    handle: panelHandleRef,
    initialValue: storedPosInit ?? { x: 0, y: 0 },
    preventDefault: true,
    onMove: (pos) => {
        const el = panelRef.value;
        if (!el) return;
        const maxX = window.innerWidth - el.offsetWidth;
        const maxY = window.innerHeight - el.offsetHeight;
        pos.x = Math.min(Math.max(pos.x, 0), Math.max(maxX, 0));
        pos.y = Math.min(Math.max(pos.y, 0), Math.max(maxY, 0));
    },
});

const panelStyle = computed<Record<string, string>>(() => {
    const empty: Record<string, string> = {};
    if (!panelMoved.value && !panelDragging.value) return empty;
    return { left: `${panelX.value}px`, top: `${panelY.value}px`, right: 'auto', bottom: 'auto' };
});

onClickOutside(settingsMenuRef, () => { settingsOpen.value = false; });

watch(panelDragging, (now, prev) => {
    if (prev && !now) {
        panelMoved.value = true;
        if (settings.value.persistPos) writeJson(POS_STATE_KEY, { x: panelX.value, y: panelY.value });
    }
});

watch(open, (now) => {
    if (settings.value.persistOpen) writeJson(OPEN_STATE_KEY, now);
    if (!now && !settings.value.persistPos) {
        panelMoved.value = false;
        panelX.value = 0;
        panelY.value = 0;
    }
});

watch(settings, (s) => writeJson(SETTINGS_KEY, s), { deep: true });

let resizeObserver: ResizeObserver | null = null;
let skipFirstResize = true;

function teardownResizeObserver(): void {
    resizeObserver?.disconnect();
    resizeObserver = null;
}

function setupResizeObserver(el: HTMLElement): void {
    teardownResizeObserver();
    skipFirstResize = true;
    resizeObserver = new ResizeObserver((entries) => {
        if (!settings.value.persistSize) return;
        if (skipFirstResize) {
            skipFirstResize = false;
            return;
        }
        const cr = entries[0].contentRect;
        writeJson(SIZE_STATE_KEY, { w: Math.round(cr.width), h: Math.round(cr.height) });
    });
    resizeObserver.observe(el);
}

watch(panelRef, async (el) => {
    if (!el) {
        teardownResizeObserver();
        return;
    }
    await nextTick();
    if (settings.value.persistSize && storedSizeInit) {
        el.style.width = `${storedSizeInit.w}px`;
        el.style.height = `${storedSizeInit.h}px`;
    }
    setupResizeObserver(el);
});

watch(() => settings.value.persistSize, (now) => {
    const el = panelRef.value;
    if (!el) return;
    if (now) {
        const current = readJson<Size>(SIZE_STATE_KEY);
        if (current) {
            el.style.width = `${current.w}px`;
            el.style.height = `${current.h}px`;
        } else {
            writeJson(SIZE_STATE_KEY, { w: Math.round(el.offsetWidth), h: Math.round(el.offsetHeight) });
        }
    }
});

watch(() => settings.value.persistPos, (now) => {
    if (now && panelMoved.value) {
        writeJson(POS_STATE_KEY, { x: panelX.value, y: panelY.value });
    }
});

watch(() => settings.value.persistOpen, (now) => {
    if (now) writeJson(OPEN_STATE_KEY, open.value);
});

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
    teardownResizeObserver();
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
        cacheEnabled.value = (parsed.config?.cache ?? false) && parsed.cache != null;
        cache.value = parsed.cache ?? null;
        cacheValueUrl.value = parsed.config?.cache_value_url ?? null;
        queueEnabled.value = parsed.config?.queue ?? false;
        queueUrl.value = parsed.config?.queue_url ?? null;
        queueDriver.value = parsed.config?.queue_driver ?? null;
        queueSupportsListing.value = parsed.config?.queue_supports_listing ?? false;
        queueHorizon.value = parsed.config?.queue_horizon ?? false;

        if (!dataLayerEnabled.value && tab.value === 'datalayer') {
            tab.value = 'snips';
        }
        if (!cacheEnabled.value && tab.value === 'cache') {
            tab.value = 'snips';
        }
        if (!queueEnabled.value && tab.value === 'queue') {
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
        ['cache', cacheEnabled.value ? (cache.value?.keys.length ?? 0) : 0],
        ['queue', queueEnabled.value ? 1 : 0],
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

const THEME_MODES = ['auto', 'light', 'dark'] as const;

const activeEntry = computed<SnipEntry | null>(() => entries.value[activeIndex.value] ?? null);

const customDataLayerCount = computed<number>(
    () => dataLayerEnabled.value ? dataLayerEvents.value.filter((e) => isCustomEvent(e.payload)).length : 0,
);

const cacheKeyCount = computed<number>(
    () => cacheEnabled.value ? (cache.value?.keys.length ?? 0) : 0,
);

const totalCount = computed<number>(
    () => entries.value.length + timings.value.length + milestones.value.length + customDataLayerCount.value + cacheKeyCount.value + (queueEnabled.value ? 1 : 0),
);

const hasContent = computed<boolean>(() => totalCount.value > 0);

const TAB_LABELS: Record<Tab, string> = {
    snips: 'Snips',
    timings: 'Timings',
    milestones: 'Milestones',
    datalayer: 'DataLayer',
    cache: 'Cache',
    queue: 'Queue',
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
        cache: cacheKeyCount.value,
        queue: 0,
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

        <div
            v-if="open"
            ref="panelRef"
            class="panel"
            :class="{ 'panel--dragging': panelDragging }"
            :style="panelStyle"
            role="dialog"
            aria-label="Laravel Snip"
        >
            <header class="panel__header">
                <div ref="panelHandleRef" class="panel__drag" title="Drag to move">
                    <svg class="icon icon--header" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="6" cy="6" r="3" />
                        <circle cx="6" cy="18" r="3" />
                        <line x1="20" y1="4" x2="8.12" y2="15.88" />
                        <line x1="14.47" y1="14.48" x2="20" y2="20" />
                        <line x1="8.12" y1="8.12" x2="12" y2="12" />
                    </svg>
                    <strong>Laravel Snip</strong>
                </div>

                <nav class="tabs">
                    <button
                        v-for="t in visibleTabs"
                        :key="t"
                        type="button"
                        class="tab"
                        :class="{ 'tab--active': tab === t }"
                        @click="tab = t"
                    >
                        {{ tabLabel(t) }} <span v-if="t !== 'queue'" class="tab__badge">{{ tabCount(t) }}</span>
                    </button>
                </nav>

                <div ref="settingsMenuRef" class="settings settings--push">
                    <button
                        class="settings__btn"
                        type="button"
                        title="Settings"
                        aria-label="Settings"
                        :aria-expanded="settingsOpen"
                        @click="settingsOpen = !settingsOpen"
                    >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="12" cy="12" r="3" />
                            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h0a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h0a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v0a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" />
                        </svg>
                    </button>
                    <div v-if="settingsOpen" class="settings__menu" role="menu">
                        <h4 class="settings__title">Theme</h4>
                        <div class="settings__seg" role="radiogroup" aria-label="Theme">
                            <label
                                v-for="m in THEME_MODES"
                                :key="m"
                                class="settings__seg-btn"
                                :class="{ 'settings__seg-btn--active': themeMode === m }"
                            >
                                <input
                                    type="radio"
                                    name="snip-theme"
                                    :value="m"
                                    v-model="themeMode"
                                />
                                <span>{{ m }}</span>
                            </label>
                        </div>

                        <h4 class="settings__title settings__title--gap">Persistence</h4>
                        <label class="settings__row">
                            <span>Remember open/closed</span>
                            <span class="toggle-sw">
                                <input type="checkbox" v-model="settings.persistOpen" />
                                <span class="toggle-sw__track"><span class="toggle-sw__thumb" /></span>
                            </span>
                        </label>
                        <label class="settings__row">
                            <span>Remember panel size</span>
                            <span class="toggle-sw">
                                <input type="checkbox" v-model="settings.persistSize" />
                                <span class="toggle-sw__track"><span class="toggle-sw__thumb" /></span>
                            </span>
                        </label>
                        <label class="settings__row">
                            <span>Remember panel position</span>
                            <span class="toggle-sw">
                                <input type="checkbox" v-model="settings.persistPos" />
                                <span class="toggle-sw__track"><span class="toggle-sw__thumb" /></span>
                            </span>
                        </label>
                        <p class="settings__note">
                            Settings and toggled state are saved in <code>localStorage</code> on this origin.
                        </p>
                    </div>
                </div>
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

            <section v-else-if="tab === 'cache'" class="panel__scroll">
                <CacheList :cache="cache" :value-url="cacheValueUrl" />
            </section>

            <section v-else-if="tab === 'queue'" class="panel__scroll">
                <QueueList
                    :url="queueUrl"
                    :driver="queueDriver"
                    :supports-listing="queueSupportsListing"
                    :horizon-available="queueHorizon"
                />
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
    position: fixed;
    bottom: 64px;
    right: 16px;
    width: min(880px, 92vw);
    height: min(560px, 70vh);
    min-width: 400px;
    min-height: 240px;
    max-width: 100vw;
    max-height: 100vh;
    background: var(--snip-bg);
    border: 1px solid var(--snip-border);
    border-radius: 12px;
    box-shadow: var(--snip-shadow);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    resize: both;
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

.panel__drag {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    cursor: grab;
    touch-action: none;
    user-select: none;
    padding: 2px 4px;
    margin: -2px -4px;
    border-radius: 6px;
}

.panel__drag:hover {
    background: var(--snip-hover);
}

.panel--dragging .panel__drag,
.panel__drag:active {
    cursor: grabbing;
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

.settings {
    position: relative;
    display: inline-flex;
}

.settings--push {
    margin-left: auto;
}

.settings__btn {
    background: transparent;
    color: var(--snip-text-muted);
    border: 1px solid var(--snip-border);
    border-radius: 6px;
    padding: 2px 8px;
    line-height: 0;
    cursor: pointer;
}

.settings__btn svg {
    width: 14px;
    height: 14px;
}

.settings__btn:hover {
    color: var(--snip-text);
    background: var(--snip-hover);
}

.settings__menu {
    position: absolute;
    top: calc(100% + 6px);
    right: 0;
    min-width: 240px;
    background: var(--snip-surface);
    border: 1px solid var(--snip-border);
    border-radius: 8px;
    box-shadow: var(--snip-shadow);
    padding: 10px 12px;
    z-index: 1;
}

.settings__title {
    margin: 0 0 8px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--snip-text-muted);
}

.settings__title--gap {
    margin-top: 12px;
}

.settings__seg {
    display: flex;
    gap: 4px;
    background: var(--snip-surface-2);
    border: 1px solid var(--snip-border);
    border-radius: 6px;
    padding: 2px;
}

.settings__seg-btn {
    flex: 1;
    text-align: center;
    padding: 4px 8px;
    font-size: 11px;
    font-weight: 600;
    text-transform: capitalize;
    color: var(--snip-text-muted);
    border-radius: 4px;
    cursor: pointer;
    user-select: none;
}

.settings__seg-btn input {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}

.settings__seg-btn:hover {
    color: var(--snip-text);
}

.settings__seg-btn--active {
    background: var(--snip-surface-3);
    color: var(--snip-text-strong);
    box-shadow: inset 0 0 0 1px var(--snip-border);
}

.settings__row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 6px 2px;
    font-size: 12px;
    color: var(--snip-text);
    cursor: pointer;
}

.toggle-sw {
    position: relative;
    display: inline-flex;
    flex-shrink: 0;
    width: 30px;
    height: 18px;
}

.toggle-sw input {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    margin: 0;
    opacity: 0;
    cursor: pointer;
    z-index: 1;
}

.toggle-sw__track {
    position: absolute;
    inset: 0;
    background: var(--snip-surface-3);
    border: 1px solid var(--snip-border);
    border-radius: 9999px;
    transition: background 0.15s ease, border-color 0.15s ease;
}

.toggle-sw__thumb {
    position: absolute;
    top: 1px;
    left: 1px;
    width: 14px;
    height: 14px;
    background: var(--snip-text-muted);
    border-radius: 50%;
    transition: transform 0.15s ease, background 0.15s ease;
}

.toggle-sw input:checked ~ .toggle-sw__track {
    background: var(--snip-accent-strong);
    border-color: var(--snip-accent-strong-2);
}

.toggle-sw input:checked ~ .toggle-sw__track .toggle-sw__thumb {
    transform: translateX(12px);
    background: #ffffff;
}

.toggle-sw input:focus-visible ~ .toggle-sw__track {
    box-shadow: 0 0 0 2px var(--snip-accent);
}

.settings__note {
    margin: 10px 0 0;
    padding-top: 8px;
    border-top: 1px solid var(--snip-border);
    font-size: 11px;
    color: var(--snip-text-faint);
    line-height: 1.4;
}

.settings__note code {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    background: var(--snip-chip-bg);
    padding: 0 4px;
    border-radius: 3px;
    font-size: 10px;
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
