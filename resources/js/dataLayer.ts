import type { DataLayerEvent } from './types';

/**
 * Site-defined event = has a string `event` key that is not a built-in GTM
 * lifecycle hook (gtm.js, gtm.dom, gtm.load, gtm.click, gtm.linkClick,
 * gtm.scrollDepth, etc.). Anything without an event key (config blobs) or
 * with a gtm.* prefix is filtered out by default.
 */
export function isCustomEvent(payload: unknown): boolean {
    if (!payload || typeof payload !== 'object') return false;

    const event = (payload as Record<string, unknown>).event;
    if (typeof event !== 'string' || event === '') return false;

    return !event.startsWith('gtm.');
}

type AnyArray = unknown[] & { push: (...args: unknown[]) => number };

/**
 * Snapshot existing window.dataLayer entries into the events ref, then patch
 * push() so future pushes also land there. Returns an uninstaller that
 * restores the previously-installed push reference (which may belong to
 * another debug tool, not necessarily the original).
 */
export function installDataLayerHook(events: { value: DataLayerEvent[] }): () => void {
    if (typeof window === 'undefined') {
        return noop;
    }

    const dataLayer = ensureDataLayer();
    const start = nowMs();
    let counter = 0;

    snapshot(dataLayer, events, () => counter++);

    const previousPush = dataLayer.push.bind(dataLayer);

    dataLayer.push = function (...args: unknown[]): number {
        const ts = Math.round(nowMs() - start);

        for (const payload of args) {
            events.value.push({
                index: counter++,
                pushed_at_ms: ts,
                payload,
            });
        }

        return previousPush.apply(dataLayer, args);
    };

    return () => {
        if (dataLayer.push !== previousPush) {
            dataLayer.push = previousPush;
        }
    };
}

function ensureDataLayer(): AnyArray {
    const w = window as unknown as { dataLayer?: AnyArray };

    if (!Array.isArray(w.dataLayer)) {
        w.dataLayer = [] as AnyArray;
    }

    return w.dataLayer!;
}

function snapshot(dataLayer: AnyArray, events: { value: DataLayerEvent[] }, nextIndex: () => number): void {
    for (const item of dataLayer) {
        events.value.push({
            index: nextIndex(),
            pushed_at_ms: 0,
            payload: item,
        });
    }
}

function nowMs(): number {
    return typeof performance !== 'undefined' ? performance.now() : Date.now();
}

function noop(): void {
    // intentional
}
