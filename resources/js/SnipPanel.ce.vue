<script setup lang="ts">
import { computed, getCurrentInstance, nextTick, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import { onClickOutside, useDraggable, useResizeObserver } from '@vueuse/core';
import ValueTree from './ValueTree.vue';
import TimingList from './TimingList.vue';
import MilestoneList from './MilestoneList.vue';
import DataLayerList from './DataLayerList.vue';
import CacheList from './CacheList.vue';
import QueueList from './QueueList.vue';
import MailList from './MailList.vue';
import InertiaProps from './InertiaProps.vue';
import ProfilerReport from './ProfilerReport.vue';
import GuestLink from './GuestLink.vue';
import UsageHint from './UsageHint.vue';
import type { DataLayerEvent, InertiaDeferredProps, InertiaPage, SnipCache, SnipEntry, SnipMail, SnipMilestone, SnipPayload, SnipProfile, SnipTiming } from './types';
import { formatBytes, shortenFile } from './format';
import { installDataLayerHook, isCustomEvent } from './dataLayer';
import { inertiaPageFromEvent, readInitialInertiaPage } from './inertia';
import { readStorage, writeStorage } from './storage';
import { useTheme } from './theme';

type Tab = 'snips' | 'timings' | 'milestones' | 'profiler' | 'inertia' | 'mail' | 'datalayer' | 'cache' | 'queue';

const TAB_STORAGE_KEY = 'laravel-snip:tab';
const SETTINGS_KEY = 'laravel-snip:settings';
const OPEN_STATE_KEY = 'laravel-snip:state:open';
const SIZE_STATE_KEY = 'laravel-snip:state:size';
const POS_STATE_KEY = 'laravel-snip:state:pos';
const ALL_TABS: Tab[] = ['snips', 'timings', 'milestones', 'profiler', 'inertia', 'mail', 'datalayer', 'cache', 'queue'];

type Corner = 'top-left' | 'top-right' | 'bottom-left' | 'bottom-right';

/** Ordered to match the 2×2 picker: the top row first, then the bottom row. */
const CORNERS: Corner[] = ['top-left', 'top-right', 'bottom-left', 'bottom-right'];

interface Settings {
    persistOpen: boolean;
    persistSize: boolean;
    persistPos: boolean;
    corner: Corner;
}

const DEFAULT_SETTINGS: Settings = {
    persistOpen: false,
    persistSize: true,
    persistPos: false,
    corner: 'bottom-right',
};

interface Size { w: number; h: number; }
interface Pos { x: number; y: number; }

type Edge = 'n' | 's' | 'e' | 'w' | 'ne' | 'nw' | 'se' | 'sw';

const RESIZE_EDGES: Edge[] = ['n', 's', 'e', 'w', 'ne', 'nw', 'se', 'sw'];
/** Small enough for a corner, big enough that the header (tabs on their own row) and the settings menu fit. */
const MIN_PANEL_WIDTH = 480;
const MIN_PANEL_HEIGHT = 440;

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

function removeKeys(...keys: string[]): void {
    try {
        if (typeof localStorage !== 'undefined') keys.forEach((key) => localStorage.removeItem(key));
    } catch {
        // ignore
    }
}

const storedSettings = readJson<Partial<Settings>>(SETTINGS_KEY);
const settings = ref<Settings>({ ...DEFAULT_SETTINGS, ...(storedSettings ?? {}) });
if (!CORNERS.includes(settings.value.corner)) settings.value.corner = DEFAULT_SETTINGS.corner;

const storedOpenInit = settings.value.persistOpen ? readJson<boolean>(OPEN_STATE_KEY) : null;
const storedPosInit = settings.value.persistPos ? readJson<Pos>(POS_STATE_KEY) : null;

const open = ref<boolean>(storedOpenInit === true);
const settingsOpen = ref<boolean>(false);
const dataLayerEnabled = ref<boolean>(true);
const mailRequestEnabled = ref<boolean>(false);
const mailInboxUrl = ref<string | null>(null);
const cacheEnabled = ref<boolean>(false);
const queueEnabled = ref<boolean>(false);
const queueUrl = ref<string | null>(null);
const queueDriver = ref<string | null>(null);
const queueSupportsListing = ref<boolean>(false);
const queueHorizon = ref<boolean>(false);
const entries = ref<SnipEntry[]>([]);
const timings = ref<SnipTiming[]>([]);
const milestones = ref<SnipMilestone[]>([]);
const mails = ref<SnipMail[]>([]);
const profile = ref<SnipProfile | null>(null);
const guestLinkUrl = ref<string | null>(null);
const viewingAsGuest = ref<boolean>(false);
const dataLayerEvents = ref<DataLayerEvent[]>([]);
const cache = ref<SnipCache | null>(null);
const cacheValueUrl = ref<string | null>(null);
/** Shallow: the page object belongs to the host app's Inertia, so it is shown as a snapshot, not proxied. */
const inertiaPage = shallowRef<InertiaPage | null>(null);
const inertiaDeferred = shallowRef<InertiaDeferredProps>({});
const activeIndex = ref(0);
const tab = ref<Tab>(readStorage(TAB_STORAGE_KEY, ALL_TABS) ?? 'snips');

const visibleTabs = computed<Tab[]>(() =>
    ALL_TABS.filter((t) => {
        if (t === 'profiler') return profile.value !== null;
        if (t === 'inertia') return inertiaPage.value !== null;
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

/** Where the panel was when the current drag began, to tell a drag from a plain click on the handle. */
let dragStart: Pos | null = null;

const {
    x: panelX,
    y: panelY,
    isDragging: panelDragging,
} = useDraggable(panelRef, {
    handle: panelHandleRef,
    initialValue: storedPosInit ?? { x: 0, y: 0 },
    preventDefault: true,
    // While anchored to its corner the panel is placed by CSS and x/y are stale (0,0 after a reset or
    // on first open). Pressing the handle switches to left/top positioning, so start from where the
    // panel really is, or it jumps to the top-left corner.
    onStart: () => {
        const el = panelRef.value;
        if (el && !panelMoved.value) {
            const rect = el.getBoundingClientRect();
            panelX.value = rect.left;
            panelY.value = rect.top;
        }
        dragStart = { x: panelX.value, y: panelY.value };
    },
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

const settingsListRef = ref<HTMLElement | null>(null);
const settingsMaxHeight = ref<string | undefined>(undefined);

/** The panel clips its children, so the settings menu is capped to the room below it and scrolls past that. */
function fitSettingsMenu(): void {
    const menu = settingsListRef.value;
    const panel = panelRef.value;
    if (!menu || !panel) return;
    settingsMaxHeight.value = `${Math.floor(panel.getBoundingClientRect().bottom - menu.getBoundingClientRect().top - 8)}px`;
}

watch(settingsOpen, async (now) => {
    if (!now) return;
    await nextTick();
    fitSettingsMenu();
});

useResizeObserver(panelRef, fitSettingsMenu);

watch(panelDragging, (now, prev) => {
    if (prev && !now) {
        const moved = dragStart === null || dragStart.x !== panelX.value || dragStart.y !== panelY.value;
        dragStart = null;
        if (!moved) return;
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

watch(panelRef, async (el) => {
    if (!el) return;
    await nextTick();
    // Read fresh rather than the value from page load: the panel unmounts on close, and a size the
    // user resized to earlier on this page has to survive reopening it.
    const stored = settings.value.persistSize ? readJson<Size>(SIZE_STATE_KEY) : null;
    if (stored) {
        el.style.width = `${stored.w}px`;
        el.style.height = `${stored.h}px`;
    }
    customSize.value = el.style.width !== '';
});

watch(() => settings.value.persistSize, (now) => {
    const el = panelRef.value;
    if (!el || !now) return;
    const current = readJson<Size>(SIZE_STATE_KEY);
    if (current) {
        el.style.width = `${current.w}px`;
        el.style.height = `${current.h}px`;
        customSize.value = true;
    } else if (el.style.width !== '') {
        // Only a size the user resized to is worth remembering; the default follows the viewport.
        writeJson(SIZE_STATE_KEY, { w: el.offsetWidth, h: el.offsetHeight });
    }
});

const panelResizing = ref<boolean>(false);

/** True while the panel has a size the user resized to, rather than the viewport-relative default. */
const customSize = ref<boolean>(false);

/**
 * Resizes the panel from any edge or corner. The panel is anchored to the chosen corner until it is first
 * moved, so a native `resize: both` grip would grow it away from the cursor; switching to left/top
 * positioning on the first resize keeps the dragged edge under the pointer.
 */
function startResize(edge: Edge, event: PointerEvent): void {
    const el = panelRef.value;
    if (!el || event.button !== 0) return;
    event.preventDefault();

    const handle = event.currentTarget as HTMLElement;
    const start = el.getBoundingClientRect();
    const clamp = (value: number, min: number, max: number): number => Math.min(Math.max(value, min), Math.max(max, min));

    panelX.value = start.left;
    panelY.value = start.top;
    panelMoved.value = true;
    panelResizing.value = true;
    handle.setPointerCapture(event.pointerId);

    const onMove = (move: PointerEvent): void => {
        const dx = move.clientX - event.clientX;
        const dy = move.clientY - event.clientY;
        let { left, top, width, height } = start;

        if (edge.includes('e')) width = clamp(start.width + dx, MIN_PANEL_WIDTH, window.innerWidth - start.left);
        if (edge.includes('s')) height = clamp(start.height + dy, MIN_PANEL_HEIGHT, window.innerHeight - start.top);
        if (edge.includes('w')) {
            width = clamp(start.width - dx, MIN_PANEL_WIDTH, start.right);
            left = start.right - width;
        }
        if (edge.includes('n')) {
            height = clamp(start.height - dy, MIN_PANEL_HEIGHT, start.bottom);
            top = start.bottom - height;
        }

        el.style.width = `${Math.round(width)}px`;
        el.style.height = `${Math.round(height)}px`;
        panelX.value = Math.round(left);
        panelY.value = Math.round(top);
    };

    const onEnd = (): void => {
        handle.removeEventListener('pointermove', onMove);
        handle.removeEventListener('pointerup', onEnd);
        handle.removeEventListener('pointercancel', onEnd);
        panelResizing.value = false;
        customSize.value = true;
        if (settings.value.persistSize) writeJson(SIZE_STATE_KEY, { w: el.offsetWidth, h: el.offsetHeight });
        if (settings.value.persistPos) writeJson(POS_STATE_KEY, { x: panelX.value, y: panelY.value });
    };

    handle.addEventListener('pointermove', onMove);
    handle.addEventListener('pointerup', onEnd);
    handle.addEventListener('pointercancel', onEnd);
}

/** Pointer travel (px) before a press on the pill counts as a drag rather than a click. */
const PILL_DRAG_THRESHOLD = 5;

const pillOffset = ref<Pos | null>(null);

/** Set when a drag ends so the click the browser fires on release does not also toggle the panel. */
let pillDragged = false;

const pillTransform = computed<string | undefined>(() =>
    pillOffset.value ? `translate(${pillOffset.value.x}px, ${pillOffset.value.y}px)` : undefined,
);

/**
 * Drags the pill across the page; on release it snaps to the corner of the quarter of the viewport
 * its centre is in, and that corner is saved like picking it under Settings → Position.
 */
function startPillDrag(event: PointerEvent): void {
    if (event.button !== 0) return;
    pillDragged = false;

    const pill = event.currentTarget as HTMLElement;

    const onMove = (move: PointerEvent): void => {
        const dx = move.clientX - event.clientX;
        const dy = move.clientY - event.clientY;
        if (!pillOffset.value && Math.hypot(dx, dy) < PILL_DRAG_THRESHOLD) return;
        if (!pillOffset.value) pill.setPointerCapture(event.pointerId);
        pillOffset.value = { x: dx, y: dy };
    };

    const onEnd = (): void => {
        pill.removeEventListener('pointermove', onMove);
        pill.removeEventListener('pointerup', onEnd);
        pill.removeEventListener('pointercancel', onEnd);
        if (!pillOffset.value) return;

        const rect = pill.getBoundingClientRect();
        const vertical = rect.top + rect.height / 2 < window.innerHeight / 2 ? 'top' : 'bottom';
        const horizontal = rect.left + rect.width / 2 < window.innerWidth / 2 ? 'left' : 'right';
        settings.value.corner = `${vertical}-${horizontal}`;
        pillOffset.value = null;
        pillDragged = true;
        nextTick(() => slidePillFrom(pill, rect));
    };

    pill.addEventListener('pointermove', onMove);
    pill.addEventListener('pointerup', onEnd);
    pill.addEventListener('pointercancel', onEnd);
}

const PILL_SLIDE_MS = 280;

/**
 * Animates the pill from where it was dropped into its new corner: it is already rendered there,
 * so it is offset back to the drop point and eased to rest (FLIP).
 */
function slidePillFrom(pill: HTMLElement, from: DOMRect): void {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    const to = pill.getBoundingClientRect();
    const dx = from.left - to.left;
    const dy = from.top - to.top;
    if (dx === 0 && dy === 0) return;

    pill.animate(
        [{ transform: `translate(${dx}px, ${dy}px)` }, { transform: 'translate(0, 0)' }],
        { duration: PILL_SLIDE_MS, easing: 'cubic-bezier(0.22, 1, 0.36, 1)' },
    );
}

function onPillClick(): void {
    if (pillDragged) {
        pillDragged = false;
        return;
    }
    open.value = !open.value;
}

function resetSize(): void {
    removeKeys(SIZE_STATE_KEY);

    const el = panelRef.value;
    if (el) {
        el.style.width = '';
        el.style.height = '';
    }
    customSize.value = false;
}

/** Puts a dragged panel back next to the pill in the chosen corner and forgets the stored spot. */
function resetPosition(): void {
    removeKeys(POS_STATE_KEY);
    panelMoved.value = false;
    panelX.value = 0;
    panelY.value = 0;
    hidePositionTip();
}

/** The header's reset button: back next to the pill, at the default size. */
function resetLayout(): void {
    resetPosition();
    resetSize();
}

function resetSettings(): void {
    settings.value = { ...DEFAULT_SETTINGS };
    themeMode.value = 'auto';
    removeKeys(OPEN_STATE_KEY);
    resetSize();
    resetPosition();
}

/** Delay before the reset-position tooltip shows, so it does not flash while the pointer passes over. */
const TIP_DELAY_MS = 300;

const positionTipVisible = ref<boolean>(false);
let positionTipTimer: number | null = null;

function showPositionTip(): void {
    if (positionTipTimer !== null) clearTimeout(positionTipTimer);
    positionTipTimer = window.setTimeout(() => (positionTipVisible.value = true), TIP_DELAY_MS);
}

function hidePositionTip(): void {
    if (positionTipTimer !== null) clearTimeout(positionTipTimer);
    positionTipTimer = null;
    positionTipVisible.value = false;
}

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
    loadInertiaPage();
    pickInitialTab();

    if (dataLayerEnabled.value) {
        uninstallDataLayerHook = installDataLayerHook(dataLayerEvents);
        pickInitialTab(); // re-check now that dataLayer events may have arrived
    }

    applyThemeAttribute();
    document.addEventListener('keydown', onKeydown);
    document.addEventListener('inertia:success', onInertiaSuccess);
    document.addEventListener('inertia:navigate', onInertiaNavigate);
});

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown);
    document.removeEventListener('inertia:success', onInertiaSuccess);
    document.removeEventListener('inertia:navigate', onInertiaNavigate);
    uninstallDataLayerHook?.();
    uninstallDataLayerHook = null;
});

watch(tab, (next) => writeStorage(TAB_STORAGE_KEY, next));

const tabsRef = ref<HTMLElement | null>(null);
const tabsMoreLeft = ref<boolean>(false);
const tabsMoreRight = ref<boolean>(false);

/** Which edges of the tab row have tabs scrolled out of view past them. */
function updateTabsOverflow(): void {
    const el = tabsRef.value;
    if (!el) return;
    tabsMoreLeft.value = el.scrollLeft > 1;
    tabsMoreRight.value = el.scrollLeft + el.clientWidth < el.scrollWidth - 1;
}

/** A vertical mouse wheel scrolls the tab row sideways when it overflows. */
function scrollTabsWithWheel(event: WheelEvent): void {
    const el = tabsRef.value;
    if (!el || el.scrollWidth <= el.clientWidth || Math.abs(event.deltaX) > Math.abs(event.deltaY)) return;
    event.preventDefault();
    el.scrollLeft += event.deltaY;
}

/** Keeps the active tab visible in the row, e.g. when the panel opens on a tab past the right edge. */
async function revealActiveTab(): Promise<void> {
    await nextTick();
    tabsRef.value?.querySelector('.tab--active')?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    updateTabsOverflow();
}

useResizeObserver(tabsRef, updateTabsOverflow);
watch([tab, visibleTabs, tabsRef], revealActiveTab);
watch(resolvedTheme, applyThemeAttribute);

function resolveHost(): HTMLElement | undefined {
    return getCurrentInstance()?.proxy?.$el?.parentNode?.host as HTMLElement | undefined;
}

function loadPayloadInto(host: HTMLElement): void {
    const raw = host.getAttribute('data-payload') ?? '';
    if (raw === '') return;

    try {
        const parsed = JSON.parse(raw) as SnipPayload | SnipEntry[];
        applyParsedPayload(parsed);
    } catch (e) {
        console.error('[laravel-snip] failed to parse payload', e);
    }
}

function applyParsedPayload(parsed: SnipPayload | SnipEntry[] | null | undefined): void {
    if (parsed == null) return;

    if (Array.isArray(parsed)) {
        // v1 backwards-compat — flat snip array
        entries.value = parsed;
        return;
    }

    entries.value = parsed.snips ?? [];
    timings.value = parsed.timings ?? [];
    milestones.value = parsed.milestones ?? [];
    mails.value = parsed.mails ?? [];
    mailRequestEnabled.value = parsed.config?.mail ?? false;
    mailInboxUrl.value = parsed.config?.mail_inbox_url ?? null;
    profile.value = parsed.profile ?? null;
    guestLinkUrl.value = parsed.config?.guest_link_url ?? null;
    viewingAsGuest.value = parsed.config?.guest ?? false;
    dataLayerEnabled.value = parsed.config?.datalayer ?? true;
    cacheEnabled.value = (parsed.config?.cache ?? false) && parsed.cache != null;
    cache.value = parsed.cache ?? null;
    cacheValueUrl.value = parsed.config?.cache_value_url ?? null;
    queueEnabled.value = parsed.config?.queue ?? false;
    queueUrl.value = parsed.config?.queue_url ?? null;
    queueDriver.value = parsed.config?.queue_driver ?? null;
    queueSupportsListing.value = parsed.config?.queue_supports_listing ?? false;
    queueHorizon.value = parsed.config?.queue_horizon ?? false;

    if (profile.value === null && tab.value === 'profiler') {
        tab.value = 'snips';
    }
    if (!dataLayerEnabled.value && tab.value === 'datalayer') {
        tab.value = 'snips';
    }
    if (!cacheEnabled.value && tab.value === 'cache') {
        tab.value = 'snips';
    }
    if (!queueEnabled.value && tab.value === 'queue') {
        tab.value = 'snips';
    }
}

/**
 * Inertia v2 dispatches `inertia:success` on the document after every
 * successful SPA navigation, with the freshly-rendered page object on
 * `event.detail.page`. The server-side `Inertia::share('_snip', …)` callback
 * ships the same payload shape we get on initial HTML, so we can hydrate the
 * panel without a full reload.
 */
function onInertiaSuccess(event: Event): void {
    const page = inertiaPageFromEvent(event);
    if (page === null) return;

    applyInertiaPage(page);

    const next = page.props._snip as SnipPayload | null | undefined;
    if (next != null) applyParsedPayload(next);
}

/**
 * `inertia:navigate` also covers the first page load and back/forward visits,
 * which fire no `inertia:success`. Partial reloads (deferred props included)
 * replace the page without it, so both events are needed. Only the page is
 * read here: on a history visit the `_snip` prop holds an earlier request's
 * captures.
 */
function onInertiaNavigate(event: Event): void {
    const page = inertiaPageFromEvent(event);
    if (page !== null) applyInertiaPage(page);
}

function loadInertiaPage(): void {
    const page = readInitialInertiaPage();
    if (page !== null) applyInertiaPage(page);

    if (inertiaPage.value === null && tab.value === 'inertia') {
        tab.value = 'snips';
    }
}

/**
 * Deferred props are listed only on the full visit that renders the page; the
 * partial reloads that follow come back without the list. Inertia v3 keeps it
 * as `initialDeferredProps`; for older versions the last list is kept while the
 * component stays the same. Not the URL too: a partial reload may change it
 * (`?page=2` when loading more), and a full visit lists its own deferred props.
 */
function applyInertiaPage(page: InertiaPage): void {
    const sameComponent = inertiaPage.value?.component === page.component;

    inertiaDeferred.value = page.initialDeferredProps ?? page.deferredProps ?? (sameComponent ? inertiaDeferred.value : {});
    inertiaPage.value = page;
}

function pickInitialTab(): void {
    if (readStorage(TAB_STORAGE_KEY, ALL_TABS)) return; // honour persisted choice

    const counts: Array<[Tab, number]> = [
        ['snips', entries.value.length],
        ['timings', timings.value.length],
        ['milestones', milestones.value.length],
        ['profiler', profile.value !== null ? 1 : 0],
        ['mail', mails.value.length],
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

function cornerLabel(corner: Corner): string {
    const label = corner.replace('-', ' ');
    return label.charAt(0).toUpperCase() + label.slice(1);
}

const SNIPS_USAGE = `// Capture any value under a label
\\Snip::add($order, 'order');`;

const activeEntry = computed<SnipEntry | null>(() => entries.value[activeIndex.value] ?? null);

const customDataLayerCount = computed<number>(
    () => dataLayerEnabled.value ? dataLayerEvents.value.filter((e) => isCustomEvent(e.payload)).length : 0,
);

/** Props of the current Inertia page, leaving out the package's own `_snip` payload. */
const inertiaPropCount = computed<number>(
    () => Object.keys(inertiaPage.value?.props ?? {}).filter((key) => key !== '_snip').length,
);

const cacheKeyCount = computed<number>(
    () => cacheEnabled.value ? (cache.value?.keys.length ?? 0) : 0,
);

const totalCount = computed<number>(
    () => entries.value.length + timings.value.length + milestones.value.length + mails.value.length + (profile.value !== null ? 1 : 0) + customDataLayerCount.value + cacheKeyCount.value + (queueEnabled.value ? 1 : 0) + (inertiaPage.value !== null ? 1 : 0),
);

const hasContent = computed<boolean>(() => totalCount.value > 0);

const TAB_LABELS: Record<Tab, string> = {
    snips: 'Snips',
    timings: 'Timings',
    milestones: 'Milestones',
    profiler: 'Profiler',
    inertia: 'Inertia',
    mail: 'Mail',
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
        profiler: 0,
        inertia: inertiaPropCount.value,
        mail: mails.value.length,
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
    <div v-if="hasContent" class="snip-root" :class="`snip-root--${settings.corner}`">
        <button
            class="toggle"
            :class="{ 'toggle--active': open, 'toggle--dragging': pillOffset }"
            :style="{ transform: pillTransform }"
            type="button"
            :title="`${open ? 'Close' : 'Open'} (Cmd+K) · drag to another corner`"
            @pointerdown="startPillDrag"
            @click="onPillClick"
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
            :class="{ 'panel--dragging': panelDragging, 'panel--resizing': panelResizing }"
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

                <nav
                    ref="tabsRef"
                    class="tabs"
                    :class="{ 'tabs--more-left': tabsMoreLeft, 'tabs--more-right': tabsMoreRight }"
                    @scroll="updateTabsOverflow"
                    @wheel="scrollTabsWithWheel"
                >
                    <button
                        v-for="t in visibleTabs"
                        :key="t"
                        type="button"
                        class="tab"
                        :class="{ 'tab--active': tab === t }"
                        @click="tab = t"
                    >
                        {{ tabLabel(t) }} <span v-if="t !== 'queue' && t !== 'profiler'" class="tab__badge">{{ tabCount(t) }}</span>
                    </button>
                </nav>

                <span v-if="panelMoved || customSize" class="tip-anchor settings--push">
                    <button
                        class="settings__btn"
                        type="button"
                        aria-label="Reset panel position and size"
                        aria-describedby="snip-position-reset-tip"
                        @click="resetLayout"
                        @mouseenter="showPositionTip"
                        @mouseleave="hidePositionTip"
                        @focus="showPositionTip"
                        @blur="hidePositionTip"
                        @keydown.esc="hidePositionTip"
                    >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <polyline points="1 4 1 10 7 10" />
                            <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10" />
                        </svg>
                    </button>
                    <span v-show="positionTipVisible" id="snip-position-reset-tip" class="tip" role="tooltip">
                        Moves the panel back next to the pill in the chosen corner at its default size, and forgets where you dragged and resized it.
                    </span>
                </span>
                <div ref="settingsMenuRef" class="settings" :class="{ 'settings--push': !panelMoved && !customSize }">
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
                    <div v-if="settingsOpen" ref="settingsListRef" class="settings__menu" :style="{ maxHeight: settingsMaxHeight }" role="menu">
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
                                <svg class="settings__seg-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <template v-if="m === 'auto'">
                                        <circle cx="12" cy="12" r="9" />
                                        <path d="M12 3a9 9 0 0 1 0 18z" fill="currentColor" />
                                    </template>
                                    <template v-else-if="m === 'light'">
                                        <circle cx="12" cy="12" r="4" />
                                        <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41" />
                                    </template>
                                    <path v-else d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" />
                                </svg>
                                <span>{{ m }}</span>
                            </label>
                        </div>

                        <div class="settings__title-row">
                            <h4 class="settings__title">Position</h4>
                            <span class="settings__title-value">{{ cornerLabel(settings.corner) }}</span>
                        </div>
                        <div class="settings__seg" role="radiogroup" aria-label="Position">
                            <label
                                v-for="c in CORNERS"
                                :key="c"
                                class="settings__seg-btn"
                                :class="{ 'settings__seg-btn--active': settings.corner === c }"
                                :title="cornerLabel(c)"
                            >
                                <input
                                    type="radio"
                                    name="snip-corner"
                                    :value="c"
                                    :aria-label="cornerLabel(c)"
                                    v-model="settings.corner"
                                />
                                <svg class="settings__corner-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <rect x="3" y="4" width="18" height="16" rx="2" />
                                    <rect
                                        :x="c.endsWith('left') ? 6 : 12"
                                        :y="c.startsWith('top') ? 7 : 13"
                                        width="6"
                                        height="4"
                                        rx="1"
                                        fill="currentColor"
                                        stroke="none"
                                    />
                                </svg>
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
                            <button
                                v-if="customSize"
                                class="settings__row-reset"
                                type="button"
                                title="Back to the default size"
                                @click.stop.prevent="resetSize"
                            >
                                reset
                            </button>
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
                        <template v-if="guestLinkUrl || viewingAsGuest">
                            <h4 class="settings__title settings__title--gap">Guest access</h4>
                            <GuestLink v-if="guestLinkUrl" :url="guestLinkUrl" />
                            <p v-else class="settings__guest">You are viewing this panel through a guest link.</p>
                        </template>

                        <p class="settings__note">
                            Settings and toggled state are saved in <code>localStorage</code> on this origin.
                            Drag any edge or corner of the panel to resize it.
                        </p>
                        <button class="settings__reset" type="button" @click="resetSettings">
                            Reset to defaults
                        </button>
                    </div>
                </div>
                <button class="close" type="button" aria-label="Close" @click="open = false">×</button>
            </header>

            <section v-if="tab === 'snips' && entries.length === 0" class="panel__scroll">
                <UsageHint message="No snips recorded on this request." :code="SNIPS_USAGE" />
            </section>

            <section v-else-if="tab === 'snips'" class="panel__body">
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

            <section v-else-if="tab === 'profiler'" class="panel__scroll">
                <ProfilerReport v-if="profile" :profile="profile" />
            </section>

            <section v-else-if="tab === 'inertia'" class="panel__scroll">
                <InertiaProps v-if="inertiaPage" :page="inertiaPage" :deferred="inertiaDeferred" />
            </section>

            <section v-else-if="tab === 'mail'" class="panel__fill">
                <MailList :mails="mails" :request-enabled="mailRequestEnabled" :inbox-url="mailInboxUrl" />
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

            <span
                v-for="edge in RESIZE_EDGES"
                :key="edge"
                class="resize"
                :class="`resize--${edge}`"
                aria-hidden="true"
                @pointerdown="startResize(edge, $event)"
            />
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
    z-index: 2147483647;
}

/* The pill sits in the chosen corner and the panel opens next to it, leaving the pill uncovered. */
.snip-root--top-left,
.snip-root--top-right { top: 16px; }
.snip-root--bottom-left,
.snip-root--bottom-right { bottom: 16px; }
.snip-root--top-left,
.snip-root--bottom-left { left: 16px; }
.snip-root--top-right,
.snip-root--bottom-right { right: 16px; }

.snip-root--top-left .panel,
.snip-root--top-right .panel { top: 64px; }
.snip-root--bottom-left .panel,
.snip-root--bottom-right .panel { bottom: 64px; }
.snip-root--top-left .panel,
.snip-root--bottom-left .panel { left: 16px; }
.snip-root--top-right .panel,
.snip-root--bottom-right .panel { right: 16px; }

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
    touch-action: none;
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.toggle:hover {
    background: var(--snip-surface-3);
    transform: translateY(-1px);
}

.toggle--dragging {
    cursor: grabbing;
    transition: none;
    user-select: none;
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
    width: min(880px, 92vw);
    height: min(560px, 70vh);
    /*
     * Keep in step with MIN_PANEL_WIDTH / MIN_PANEL_HEIGHT. A small viewport wins over them: the panel
     * sits 16px from the side and 64px from the bottom (above the pill), and must not run off the far edge.
     */
    min-width: min(480px, calc(100vw - 32px));
    min-height: min(440px, calc(100vh - 80px));
    max-width: calc(100vw - 32px);
    max-height: calc(100vh - 80px);
    /* Stored sizes are read back from offsetWidth/offsetHeight, which include the border. */
    box-sizing: border-box;
    background: var(--snip-bg);
    border: 1px solid var(--snip-border);
    border-radius: 12px;
    box-shadow: var(--snip-shadow);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.panel--resizing {
    user-select: none;
}

.resize {
    position: absolute;
    z-index: 2;
    touch-action: none;
}

.resize--n,
.resize--s {
    left: 12px;
    right: 12px;
    height: 6px;
    cursor: ns-resize;
}

.resize--e,
.resize--w {
    top: 12px;
    bottom: 12px;
    width: 6px;
    cursor: ew-resize;
}

.resize--n { top: 0; }
.resize--s { bottom: 0; }
.resize--e { right: 0; }
.resize--w { left: 0; }

.resize--ne,
.resize--nw,
.resize--se,
.resize--sw {
    width: 14px;
    height: 14px;
}

.resize--nw { top: 0; left: 0; cursor: nwse-resize; }
.resize--se { bottom: 0; right: 0; cursor: nwse-resize; }
.resize--ne { top: 0; right: 0; cursor: nesw-resize; }
.resize--sw { bottom: 0; left: 0; cursor: nesw-resize; }

/* Grip hint in the corner the native resizer used to occupy. */
.resize--se::after {
    content: '';
    position: absolute;
    right: 3px;
    bottom: 3px;
    width: 7px;
    height: 7px;
    border-right: 2px solid var(--snip-text-faint);
    border-bottom: 2px solid var(--snip-text-faint);
    border-bottom-right-radius: 3px;
    opacity: 0.6;
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
    white-space: nowrap;
}

/* Only the tabs give way in a narrow panel (they scroll), so the title and every button stay in view. */
.panel__drag,
.settings,
.tip-anchor,
.close {
    flex-shrink: 0;
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

/*
 * One row whatever the panel width: tabs that do not fit scroll sideways, with the scrollbar hidden
 * (it would render light inside the shadow root) and a fade on each edge that has more tabs past it.
 */
.tabs {
    display: flex;
    flex: 1 1 auto;
    min-width: 0;
    gap: 4px;
    margin-left: 8px;
    overflow-x: auto;
    scrollbar-width: none;
    --fade-left: 0px;
    --fade-right: 0px;
    mask-image: linear-gradient(to right, transparent, #000 var(--fade-left), #000 calc(100% - var(--fade-right)), transparent);
}

.tabs::-webkit-scrollbar {
    display: none;
}

.tabs--more-left {
    --fade-left: 28px;
}

.tabs--more-right {
    --fade-right: 28px;
}

.tab {
    flex-shrink: 0;
    white-space: nowrap;
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
    min-width: 280px;
    background: var(--snip-surface);
    border: 1px solid var(--snip-border);
    border-radius: 8px;
    box-shadow: var(--snip-shadow);
    padding: 10px 12px;
    z-index: 1;
    box-sizing: border-box;
    overflow-y: auto;
    overscroll-behavior: contain;
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

.settings__title-row {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 12px 0 8px;
}

.settings__title-row .settings__title {
    margin: 0;
}

.settings__title-row .settings__title-value {
    margin-left: auto;
}


.tip-anchor {
    position: relative;
    display: inline-flex;
}

/* Opens below the header button: the panel clips anything drawn above its top edge. */
.tip {
    position: absolute;
    top: calc(100% + 8px);
    right: -4px;
    width: 220px;
    padding: 6px 9px;
    background: var(--snip-text-strong);
    color: var(--snip-bg);
    border-radius: 6px;
    box-shadow: var(--snip-shadow-pill);
    font-size: 11px;
    font-weight: 500;
    line-height: 1.4;
    text-transform: none;
    letter-spacing: 0;
    pointer-events: none;
    z-index: 2;
}

.tip::after {
    content: '';
    position: absolute;
    bottom: 100%;
    right: 14px;
    border: 5px solid transparent;
    border-bottom-color: var(--snip-text-strong);
}

.settings__title-value {
    font-size: 11px;
    font-weight: 500;
    text-transform: none;
    letter-spacing: 0;
    color: var(--snip-text-faint);
}

.settings__corner-icon {
    width: 16px;
    height: 16px;
}

.settings__seg-btn:has(input:focus-visible) {
    box-shadow: inset 0 0 0 1px var(--snip-accent);
}

.settings__seg-btn {
    flex: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 4px 8px;
    font-size: 11px;
    font-weight: 600;
    text-transform: capitalize;
    color: var(--snip-text-muted);
    border-radius: 4px;
    cursor: pointer;
    user-select: none;
}

.settings__seg-icon {
    width: 13px;
    height: 13px;
    flex-shrink: 0;
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

.settings__row-reset {
    margin-left: auto;
    background: transparent;
    border: 1px solid var(--snip-border);
    color: var(--snip-text-muted);
    border-radius: 6px;
    padding: 1px 8px;
    font: inherit;
    font-size: 11px;
    cursor: pointer;
}

.settings__row-reset:hover {
    background: var(--snip-surface-2);
    color: var(--snip-text);
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

.settings__guest {
    margin: 0;
    font-size: 11px;
    color: var(--snip-text-muted);
}

.settings__reset {
    margin-top: 8px;
    width: 100%;
    background: transparent;
    border: 1px solid var(--snip-border);
    color: var(--snip-text-muted);
    border-radius: 6px;
    padding: 4px 10px;
    font: inherit;
    font-size: 11px;
    cursor: pointer;
}

.settings__reset:hover {
    background: var(--snip-surface-2);
    color: var(--snip-text);
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

.panel__fill {
    flex: 1;
    min-height: 0;
    display: flex;
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
