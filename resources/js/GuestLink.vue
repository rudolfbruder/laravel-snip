<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useClipboard } from '@vueuse/core';

const props = defineProps<{
    /** The guest-link endpoint; GET reads the active link, POST replaces it, DELETE revokes it. */
    url: string;
}>();

type Link = { url: string | null; expires_at: number | null; created_at: number | null; created_by: string | null };

const link = ref<Link | null>(null);
const busy = ref<boolean>(false);
const error = ref<string | null>(null);

const { copy, copied } = useClipboard({ legacy: true, copiedDuring: 1500 });

function clock(at: number | null | undefined): string {
    return at ? new Date(at * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';
}

const expiresAt = computed<string>(() => clock(link.value?.expires_at));
const createdAt = computed<string>(() => clock(link.value?.created_at));

/**
 * POST and DELETE pass through Laravel's CSRF check. The framework mirrors the token into the
 * readable XSRF-TOKEN cookie on every web response, and accepts it back as X-XSRF-TOKEN.
 */
function csrfHeaders(): Record<string, string> {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    if (match) return { 'X-XSRF-TOKEN': decodeURIComponent(match[1]) };

    const meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]');
    return meta ? { 'X-CSRF-TOKEN': meta.content } : {};
}

async function request(method: 'GET' | 'POST' | 'DELETE'): Promise<void> {
    busy.value = true;
    error.value = null;

    try {
        const res = await fetch(props.url, {
            method,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(method === 'GET' ? {} : csrfHeaders()) },
        });

        if (!res.ok) throw new Error(res.status === 419 ? 'Session expired — reload the page.' : `HTTP ${res.status}`);

        link.value = (await res.json()) as Link;
    } catch (e) {
        error.value = e instanceof Error ? e.message : String(e);
    } finally {
        busy.value = false;
    }
}

onMounted(() => request('GET'));
</script>

<template>
    <div class="guest">
        <template v-if="link?.url">
            <div class="guest__link">
                <input class="guest__url" type="text" readonly :value="link.url" @focus="($event.target as HTMLInputElement).select()" />
                <button class="guest__btn" type="button" @click="copy(link.url)">{{ copied ? '✓' : 'copy' }}</button>
            </div>
            <p class="guest__meta">
                <template v-if="link.created_by">Created by {{ link.created_by }} at {{ createdAt }}. </template>Valid until {{ expiresAt }}.
                Open it in a browser that is not logged in.
            </p>
            <div class="guest__actions">
                <button class="guest__btn" type="button" :disabled="busy" title="Create a new link; the current one stops working" @click="request('POST')">
                    New link
                </button>
                <button class="guest__btn guest__btn--danger" type="button" :disabled="busy" @click="request('DELETE')">
                    Revoke
                </button>
            </div>
        </template>

        <template v-else-if="link">
            <p class="guest__meta">Share the panel with a browser that is not logged in.</p>
            <button class="guest__btn guest__btn--wide" type="button" :disabled="busy" @click="request('POST')">
                Create guest link
            </button>
        </template>

        <p v-if="error" class="guest__error">{{ error }}</p>
    </div>
</template>

<style scoped>
.guest {
    display: flex;
    flex-direction: column;
    gap: 6px;
    font-size: 12px;
    color: var(--snip-text);
}

.guest__link {
    display: flex;
    gap: 6px;
}

.guest__url {
    flex: 1;
    min-width: 0;
    background: var(--snip-bg);
    border: 1px solid var(--snip-border);
    border-radius: 6px;
    color: var(--snip-text);
    padding: 3px 6px;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 11px;
}

.guest__meta {
    margin: 0;
    color: var(--snip-text-faint);
    font-size: 11px;
    line-height: 1.4;
}

.guest__actions {
    display: flex;
    gap: 6px;
}

.guest__btn {
    background: transparent;
    border: 1px solid var(--snip-border);
    color: var(--snip-text-muted);
    border-radius: 6px;
    padding: 3px 10px;
    font: inherit;
    font-size: 11px;
    cursor: pointer;
    flex-shrink: 0;
}

.guest__btn:hover:not(:disabled) {
    background: var(--snip-surface-2);
    color: var(--snip-text);
}

.guest__btn:disabled {
    opacity: 0.5;
    cursor: default;
}

.guest__btn--wide {
    width: 100%;
}

.guest__btn--danger {
    color: var(--snip-bad);
}

.guest__error {
    margin: 0;
    color: var(--snip-bad);
    font-size: 11px;
}
</style>
