import type { InertiaPage } from './types';

/**
 * Read the page object Inertia rendered into the initial HTML. Inertia v3 (and
 * v2 with `useScriptElementForInitialPage`) writes it into
 * `<script data-page="app" type="application/json">`; v1 and v2 put it in the
 * `data-page` attribute of the root element. Later visits arrive through the
 * `inertia:navigate` / `inertia:success` events instead.
 */
export function readInitialInertiaPage(): InertiaPage | null {
    const script = document.querySelector('script[data-page][type="application/json"]');
    const raw = script ? script.textContent : document.querySelector('[data-page]')?.getAttribute('data-page');

    if (!raw) return null;

    try {
        return toInertiaPage(JSON.parse(raw));
    } catch {
        return null;
    }
}

/** The page object of an `inertia:*` event, or null when the event carries none. */
export function inertiaPageFromEvent(event: Event): InertiaPage | null {
    return toInertiaPage((event as CustomEvent).detail?.page);
}

function toInertiaPage(value: unknown): InertiaPage | null {
    const page = value as Partial<InertiaPage> | null | undefined;

    return page && typeof page.component === 'string' && page.props !== null && typeof page.props === 'object'
        ? (page as InertiaPage)
        : null;
}
