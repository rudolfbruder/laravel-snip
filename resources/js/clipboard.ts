import { shallowRef } from 'vue';
import { useClipboard } from '@vueuse/core';

// One clipboard for every copy button that copies a value: a tree can hold hundreds of them.
const { copy, isSupported } = useClipboard({ legacy: true });

export const canCopyValues = isSupported;

/** What was copied last, for 1.5s: buttons compare it with their own target to show they copied. */
export const copiedTarget = shallowRef<unknown>(null);

let resetTimer: number | null = null;

/** Copies a value as the app received it: strings as plain text, everything else as pretty JSON. */
export async function copyValue(target: unknown, value: unknown): Promise<void> {
    await copy(typeof value === 'string' ? value : JSON.stringify(value, null, 2));

    copiedTarget.value = target;
    if (resetTimer !== null) clearTimeout(resetTimer);
    resetTimer = window.setTimeout(() => (copiedTarget.value = null), 1500);
}
