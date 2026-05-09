/**
 * Tiny localStorage wrapper that swallows errors (private mode / sandboxed
 * frames may throw on read or write). Caller supplies the type — invalid
 * stored values are returned as null.
 */
export function readStorage<T extends string>(key: string, allowed: readonly T[]): T | null {
    try {
        if (typeof localStorage === 'undefined') return null;
        const raw = localStorage.getItem(key) as T | null;
        return raw && allowed.includes(raw) ? raw : null;
    } catch {
        return null;
    }
}

export function writeStorage(key: string, value: string): void {
    try {
        if (typeof localStorage !== 'undefined') localStorage.setItem(key, value);
    } catch {
        // ignore
    }
}
