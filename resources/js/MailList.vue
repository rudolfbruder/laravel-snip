<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { SnipMail } from './types';
import { formatBytes } from './format';
import UsageHint from './UsageHint.vue';

const props = defineProps<{
    mails: SnipMail[];
}>();

type View = 'html' | 'text' | 'source';

const USAGE = `// Any mail the request sends shows up here
Mail::to($user)->send(new OrderShipped($order));
$user->notify(new InvoicePaid($invoice));`;

const VIEW_LABELS: Record<View, string> = { html: 'HTML', text: 'Text', source: 'HTML source' };

const activeIndex = ref(0);
const view = ref<View>('html');

const active = computed<SnipMail | null>(() => props.mails[activeIndex.value] ?? null);

const views = computed<View[]>(() => {
    const mail = active.value;
    if (!mail) return [];
    const available: View[] = [];
    if (mail.html !== null) available.push('html');
    if (mail.text !== null) available.push('text');
    if (mail.html !== null) available.push('source');
    return available;
});

/** Recipient rows of the envelope, skipping the empty ones. */
const envelope = computed<Array<[string, string]>>(() => {
    const mail = active.value;
    if (!mail) return [];
    const rows: Array<[string, string[] | string | null]> = [
        ['from', mail.from],
        ['to', mail.to],
        ['cc', mail.cc],
        ['bcc', mail.bcc],
        ['reply to', mail.reply_to],
        ['built by', mail.source],
        ['mailer', mail.mailer],
    ];
    return rows
        .map(([label, value]): [string, string] => [label, Array.isArray(value) ? value.join(', ') : (value ?? '')])
        .filter(([, value]) => value !== '');
});

// A new request brings its own mail; start from the first one again.
watch(() => props.mails, () => (activeIndex.value = 0));

// Keep the chosen view when switching mail, unless that mail has no such body.
watch(views, (available) => {
    if (available.length > 0 && !available.includes(view.value)) view.value = available[0];
}, { immediate: true });

function recipients(mail: SnipMail): string {
    const all = [...mail.to, ...mail.cc, ...mail.bcc];
    if (all.length === 0) return 'no recipients';
    return all.length === 1 ? all[0] : `${all[0]} +${all.length - 1}`;
}
</script>

<template>
    <div v-if="mails.length === 0" class="empty">
        <UsageHint message="No mail sent on this request." :code="USAGE" />
    </div>

    <div v-else class="mail">
        <aside class="list">
            <button
                v-for="(mail, index) in mails"
                :key="index"
                class="item"
                :class="{ 'item--active': index === activeIndex }"
                type="button"
                @click="activeIndex = index"
            >
                <span class="item__subject">{{ mail.subject || '(no subject)' }}</span>
                <span class="item__to">{{ recipients(mail) }}</span>
                <span class="item__time">
                    {{ mail.time_ms }} ms
                    <span v-if="mail.attachments.length"> · {{ mail.attachments.length }} attachment{{ mail.attachments.length === 1 ? '' : 's' }}</span>
                </span>
            </button>
        </aside>

        <article v-if="active" class="detail">
            <header class="detail__header">
                <strong class="detail__subject">{{ active.subject || '(no subject)' }}</strong>
                <span class="muted">sent at {{ active.time_ms }} ms</span>
            </header>

            <dl class="envelope">
                <template v-for="[label, value] in envelope" :key="label">
                    <dt>{{ label }}</dt>
                    <dd>{{ value }}</dd>
                </template>
            </dl>

            <ul v-if="active.attachments.length" class="attachments">
                <li
                    v-for="(file, i) in active.attachments"
                    :key="i"
                    class="attachment"
                    :title="file.content_type"
                >
                    {{ file.name ?? 'unnamed' }}
                    <span class="attachment__meta">{{ formatBytes(file.bytes) }}<span v-if="file.inline"> · inline</span></span>
                </li>
            </ul>

            <nav v-if="views.length > 1" class="views">
                <button
                    v-for="v in views"
                    :key="v"
                    type="button"
                    class="views__btn"
                    :class="{ 'views__btn--active': view === v }"
                    @click="view = v"
                >
                    {{ VIEW_LABELS[v] }}
                </button>
            </nav>

            <!-- No sandbox permissions: the mail's scripts never run and it cannot reach the page. -->
            <iframe
                v-if="view === 'html' && active.html !== null"
                class="preview"
                sandbox=""
                :srcdoc="active.html"
                title="HTML body"
            />
            <pre v-else-if="view === 'text' && active.text !== null" class="body">{{ active.text }}</pre>
            <pre v-else-if="view === 'source' && active.html !== null" class="body">{{ active.html }}</pre>
            <p v-else class="muted">This mail has no body.</p>
        </article>
    </div>
</template>

<style scoped>
.empty {
    flex: 1;
    overflow-y: auto;
    padding: 14px 18px;
}

.mail {
    flex: 1;
    min-height: 0;
    display: grid;
    grid-template-columns: 240px 1fr;
}

.list {
    border-right: 1px solid var(--snip-border);
    overflow-y: auto;
    background: var(--snip-surface);
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

.detail {
    display: flex;
    flex-direction: column;
    gap: 12px;
    min-width: 0;
    overflow-y: auto;
    padding: 14px 18px;
}

.detail__header {
    display: flex;
    align-items: baseline;
    gap: 10px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--snip-border);
}

.detail__subject {
    color: var(--snip-text-strong);
    word-break: break-word;
}

.muted {
    margin: 0;
    color: var(--snip-text-muted);
    font-size: 12px;
    white-space: nowrap;
}

.envelope {
    display: grid;
    grid-template-columns: 80px 1fr;
    gap: 4px 12px;
    margin: 0;
    font-size: 12px;
}

.envelope dt {
    color: var(--snip-text-faint);
    text-transform: uppercase;
    letter-spacing: 0.04em;
    font-size: 10px;
    line-height: 18px;
}

.envelope dd {
    margin: 0;
    color: var(--snip-text);
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 11.5px;
    line-height: 18px;
    word-break: break-all;
}

.attachments {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin: 0;
    padding: 0;
    list-style: none;
}

.attachment {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 2px 8px;
    background: var(--snip-surface-2);
    border: 1px solid var(--snip-border);
    border-radius: 6px;
    font-size: 11px;
    color: var(--snip-text);
}

.attachment__meta {
    color: var(--snip-text-faint);
}

.views {
    display: flex;
    gap: 4px;
}

.views__btn {
    background: var(--snip-surface);
    border: 1px solid var(--snip-border);
    color: var(--snip-text-muted);
    padding: 3px 10px;
    font-size: 11px;
    font-weight: 600;
    border-radius: 6px;
    cursor: pointer;
    font-family: inherit;
}

.views__btn:hover {
    background: var(--snip-surface-2);
    color: var(--snip-text);
}

.views__btn--active {
    background: var(--snip-accent-strong);
    border-color: var(--snip-accent-strong-2);
    color: #ffffff;
}

/* Mail is written for a white background whatever the panel theme. */
.preview {
    flex: 1 0 360px;
    width: 100%;
    border: 1px solid var(--snip-border);
    border-radius: 6px;
    background: #ffffff;
    color-scheme: light;
}

/* Wrapped rather than scrolled: the shadow root resets color-scheme, so a scrollbar would render light. */
.body {
    margin: 0;
    padding: 10px 12px;
    background: var(--snip-surface);
    border: 1px solid var(--snip-border);
    border-radius: 6px;
    color: var(--snip-text);
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 11.5px;
    line-height: 1.5;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}
</style>
