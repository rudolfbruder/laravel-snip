import { computed, ref, watch } from 'vue';

export type ThemeMode = 'light' | 'dark' | 'auto';

const STORAGE_KEY = 'laravel-snip:theme';
const VALID_MODES: ThemeMode[] = ['light', 'dark', 'auto'];

const initial = readStored() ?? 'auto';
const mode = ref<ThemeMode>(initial);
const systemPrefersDark = ref<boolean>(systemPrefersDarkInitial());

let listenerAttached = false;

function attachSystemListener(): void {
    if (listenerAttached || typeof window === 'undefined' || !window.matchMedia) return;
    const mq = window.matchMedia('(prefers-color-scheme: dark)');
    const handler = (e: MediaQueryListEvent): void => {
        systemPrefersDark.value = e.matches;
    };
    if (mq.addEventListener) mq.addEventListener('change', handler);
    else mq.addListener(handler);
    listenerAttached = true;
}

attachSystemListener();

watch(mode, (next) => {
    try {
        if (typeof localStorage !== 'undefined') localStorage.setItem(STORAGE_KEY, next);
    } catch {
        // storage may be unavailable (private mode, sandboxed iframes)
    }
});

const resolved = computed<'light' | 'dark'>(() => {
    if (mode.value === 'auto') return systemPrefersDark.value ? 'dark' : 'light';
    return mode.value;
});

const nextMode: Record<ThemeMode, ThemeMode> = {
    light: 'dark',
    dark: 'auto',
    auto: 'light',
};

export function useTheme() {
    return {
        mode,
        resolved,
        cycle(): void {
            mode.value = nextMode[mode.value];
        },
    };
}

function readStored(): ThemeMode | null {
    try {
        if (typeof localStorage === 'undefined') return null;
        const raw = localStorage.getItem(STORAGE_KEY) as ThemeMode | null;
        return raw && VALID_MODES.includes(raw) ? raw : null;
    } catch {
        return null;
    }
}

function systemPrefersDarkInitial(): boolean {
    if (typeof window === 'undefined' || !window.matchMedia) return true;
    return window.matchMedia('(prefers-color-scheme: dark)').matches;
}
