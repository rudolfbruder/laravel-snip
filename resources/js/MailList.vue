<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useIntervalFn } from '@vueuse/core';
import type { SnipMail, SnipMailSummary } from './types';
import { csrfHeaders } from './csrf';
import MailDetail from './MailDetail.vue';
import UsageHint from './UsageHint.vue';

const props = defineProps<{
    /** Mail this request sent; shown when `requestEnabled`. */
    mails: SnipMail[];
    requestEnabled: boolean;
    /** The inbox endpoint, set when the app sends its mail through the `snip` mailer. */
    inboxUrl: string | null;
}>();

type Source = 'request' | 'inbox';

/** How often the open inbox checks for mail sent by commands and workers. */
const INBOX_POLL_MS = 5000;

const REQUEST_USAGE = `// Any mail the request sends shows up here
Mail::to($user)->send(new OrderShipped($order));
$user->notify(new InvoicePaid($invoice));`;

const INBOX_USAGE = `# Queue workers read the config once, when they start.
# Restart them after turning catching on, or their mail is
# still sent (php artisan horizon:terminate with Horizon)
php artisan queue:restart`;

const SETUP = `# .env, pick one or both:

# Show the mail each request sends. It is still delivered.
SNIP_MAIL=true

# Catch every mail instead of sending it, whichever mailer
# sends it, from requests, artisan commands and queue
# workers. Nothing reaches a real inbox, so use it locally
# or on staging only.
SNIP_MAIL_CATCH=true

# Then refresh the config, and restart queue workers so
# they pick it up (php artisan horizon:terminate with Horizon)
php artisan config:clear
php artisan queue:restart`;

const configured = computed<boolean>(() => props.requestEnabled || props.inboxUrl !== null);

const source = ref<Source>(props.requestEnabled && (props.mails.length > 0 || !props.inboxUrl) ? 'request' : 'inbox');
const showSwitch = computed<boolean>(() => props.requestEnabled && props.inboxUrl !== null);

/* This request */

const requestIndex = ref(0);

// A new request brings its own mail; start from the first one again.
watch(() => props.mails, () => (requestIndex.value = 0));

/* Inbox */

const summaries = ref<SnipMailSummary[]>([]);
const inboxStatus = ref<'idle' | 'loading' | 'loaded' | 'error'>('idle');
const inboxError = ref<string | null>(null);
const selectedId = ref<string | null>(null);
/** Opened mails by id; null while loading, a string when it could not be loaded. */
const opened = reactive<Record<string, SnipMail | string | null>>({});

const selected = computed<SnipMail | string | null>(() => (selectedId.value === null ? null : opened[selectedId.value] ?? null));

async function call(url: string, method: 'GET' | 'DELETE' = 'GET'): Promise<unknown> {
    const res = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(method === 'GET' ? {} : csrfHeaders()) },
    });

    if (!res.ok) {
        let message = res.status === 419 ? 'Session expired — reload the page.' : `HTTP ${res.status}`;
        try {
            message = ((await res.json()) as { message?: string }).message || message;
        } catch {
            // keep the status
        }
        throw new Error(message);
    }

    return res.json();
}

async function loadInbox(): Promise<void> {
    if (!props.inboxUrl) return;
    if (inboxStatus.value !== 'loaded') inboxStatus.value = 'loading';

    try {
        const data = (await call(props.inboxUrl)) as { mails: SnipMailSummary[] };
        summaries.value = data.mails;
        inboxStatus.value = 'loaded';
        inboxError.value = null;

        if (!summaries.value.some((m) => m.id === selectedId.value)) {
            selectedId.value = summaries.value[0]?.id ?? null;
        }
    } catch (e) {
        inboxStatus.value = 'error';
        inboxError.value = e instanceof Error ? e.message : String(e);
    }
}

async function open(id: string): Promise<void> {
    selectedId.value = id;
    if (!props.inboxUrl || (opened[id] !== undefined && typeof opened[id] !== 'string')) return;

    opened[id] = null;
    try {
        opened[id] = (await call(`${props.inboxUrl}/${encodeURIComponent(id)}`)) as SnipMail;
    } catch (e) {
        opened[id] = e instanceof Error ? e.message : String(e);
    }
}

async function clearInbox(): Promise<void> {
    if (!props.inboxUrl) return;

    try {
        await call(props.inboxUrl, 'DELETE');
        summaries.value = [];
        selectedId.value = null;
    } catch (e) {
        inboxStatus.value = 'error';
        inboxError.value = e instanceof Error ? e.message : String(e);
    }
}

// Mail from commands and workers arrives while the page is open, so the inbox keeps checking.
const { pause, resume } = useIntervalFn(loadInbox, INBOX_POLL_MS, { immediate: false });

watch([source, () => props.inboxUrl], () => {
    if (source.value === 'inbox' && props.inboxUrl) {
        loadInbox();
        resume();
    } else {
        pause();
    }
}, { immediate: true });

watch(selectedId, (id) => {
    if (id !== null) open(id);
});

function recipients(to: string[]): string {
    if (to.length === 0) return 'no recipients';
    return to.length === 1 ? to[0] : `${to[0]} +${to.length - 1}`;
}

function clock(iso: string | null): string {
    if (!iso) return '';
    const date = new Date(iso);
    return date.toDateString() === new Date().toDateString() ? date.toLocaleTimeString() : date.toLocaleString();
}
</script>

<template>
    <div v-if="!configured" class="empty empty--full">
        <UsageHint message="Mail is not captured yet. Turn it on in your .env, then reload this page." :code="SETUP" />
    </div>

    <div v-else class="mail">
        <aside class="list">
            <div v-if="showSwitch || source === 'inbox'" class="toolbar">
                <div v-if="showSwitch" class="switch" role="tablist">
                    <button type="button" class="switch__btn" :class="{ 'switch__btn--active': source === 'request' }" @click="source = 'request'">
                        This request <span class="switch__count">{{ mails.length }}</span>
                    </button>
                    <button type="button" class="switch__btn" :class="{ 'switch__btn--active': source === 'inbox' }" @click="source = 'inbox'">
                        Inbox <span v-if="inboxStatus === 'loaded'" class="switch__count">{{ summaries.length }}</span>
                    </button>
                </div>
                <div v-if="source === 'inbox'" class="toolbar__actions">
                    <span class="toolbar__note">caught instead of sent</span>
                    <button type="button" class="toolbar__btn" title="Check for new mail" @click="loadInbox">↻</button>
                    <button type="button" class="toolbar__btn" :disabled="summaries.length === 0" title="Delete every caught mail" @click="clearInbox">clear</button>
                </div>
            </div>

            <template v-if="source === 'request'">
                <button
                    v-for="(mail, index) in mails"
                    :key="index"
                    class="item"
                    :class="{ 'item--active': index === requestIndex }"
                    type="button"
                    @click="requestIndex = index"
                >
                    <span class="item__subject">{{ mail.subject || '(no subject)' }}</span>
                    <span class="item__to">{{ recipients([...mail.to, ...mail.cc, ...mail.bcc]) }}</span>
                    <span class="item__time">
                        {{ mail.time_ms }} ms
                        <span v-if="mail.attachments.length"> · {{ mail.attachments.length }} attachment{{ mail.attachments.length === 1 ? '' : 's' }}</span>
                    </span>
                </button>
            </template>

            <template v-else>
                <p v-if="inboxStatus === 'loading'" class="list__note">Loading…</p>
                <p v-else-if="inboxStatus === 'error'" class="list__note list__note--error">{{ inboxError }}</p>
                <button
                    v-for="mail in summaries"
                    :key="mail.id"
                    class="item"
                    :class="{ 'item--active': mail.id === selectedId }"
                    type="button"
                    @click="selectedId = mail.id"
                >
                    <span class="item__subject">{{ mail.subject || '(no subject)' }}</span>
                    <span class="item__to">{{ recipients(mail.to) }}</span>
                    <span class="item__time">
                        {{ clock(mail.sent_at) }}<span v-if="mail.origin"> · {{ mail.origin }}</span>
                        <span v-if="mail.attachments"> · {{ mail.attachments }} attachment{{ mail.attachments === 1 ? '' : 's' }}</span>
                    </span>
                </button>
            </template>
        </aside>

        <template v-if="source === 'request'">
            <div v-if="mails.length === 0" class="empty">
                <UsageHint message="No mail sent on this request." :code="REQUEST_USAGE" />
            </div>
            <MailDetail v-else-if="mails[requestIndex]" :mail="mails[requestIndex]" />
        </template>

        <template v-else>
            <div v-if="inboxStatus === 'loaded' && summaries.length === 0" class="empty">
                <UsageHint message="No mail caught yet. Send one from a page, a command or a queued job; it shows up here within a few seconds." :code="INBOX_USAGE" />
            </div>
            <p v-else-if="selected === null" class="empty muted">{{ selectedId ? 'Loading…' : '' }}</p>
            <p v-else-if="typeof selected === 'string'" class="empty muted">{{ selected }}</p>
            <MailDetail v-else :mail="selected" />
        </template>
    </div>
</template>

<style scoped>
.mail {
    flex: 1;
    min-height: 0;
    display: grid;
    grid-template-columns: 240px 1fr;
}

.empty {
    overflow-y: auto;
    padding: 14px 18px;
}

.empty--full {
    flex: 1;
}

.muted {
    margin: 0;
    color: var(--snip-text-muted);
    font-size: 12px;
}

.list {
    display: flex;
    flex-direction: column;
    border-right: 1px solid var(--snip-border);
    overflow-y: auto;
    background: var(--snip-surface);
}

.list__note {
    margin: 0;
    padding: 10px 14px;
    color: var(--snip-text-muted);
    font-size: 12px;
}

.list__note--error {
    color: var(--snip-bad);
}

.toolbar {
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding: 10px 12px;
    border-bottom: 1px solid var(--snip-border);
}

.switch {
    display: flex;
    gap: 2px;
    background: var(--snip-surface-2);
    border: 1px solid var(--snip-border);
    border-radius: 6px;
    padding: 2px;
}

.switch__btn {
    flex: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 3px 6px;
    background: transparent;
    border: none;
    border-radius: 4px;
    color: var(--snip-text-muted);
    font-family: inherit;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
}

.switch__btn:hover {
    color: var(--snip-text);
}

.switch__btn--active {
    background: var(--snip-surface-3);
    color: var(--snip-text-strong);
    box-shadow: inset 0 0 0 1px var(--snip-border);
}

.switch__count {
    background: var(--snip-chip-bg);
    padding: 0 6px;
    border-radius: 9999px;
    font-size: 10px;
    line-height: 1.4;
}

.toolbar__actions {
    display: flex;
    align-items: center;
    gap: 6px;
}

.toolbar__note {
    flex: 1;
    color: var(--snip-text-faint);
    font-size: 10.5px;
    line-height: 1.3;
}

.toolbar__note code {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
}

.toolbar__btn {
    flex-shrink: 0;
    background: transparent;
    border: 1px solid var(--snip-border);
    color: var(--snip-text-muted);
    border-radius: 6px;
    padding: 1px 8px;
    font-family: inherit;
    font-size: 11px;
    cursor: pointer;
}

.toolbar__btn:hover:not(:disabled) {
    background: var(--snip-surface-2);
    color: var(--snip-text);
}

.toolbar__btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}

.item {
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

.item:hover {
    background: var(--snip-surface-2);
}

.item--active {
    background: var(--snip-surface-3);
}

.item__subject {
    font-weight: 600;
    color: var(--snip-text-strong);
    word-break: break-word;
}

.item__to {
    color: var(--snip-text-faint);
    font-size: 11px;
    word-break: break-all;
}

.item__time {
    color: var(--snip-text-fainter);
    font-size: 10px;
}
</style>
