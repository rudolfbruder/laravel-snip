<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { SnipMail } from './types';
import { formatBytes } from './format';

const props = defineProps<{
    mail: SnipMail;
}>();

type View = 'html' | 'text' | 'source';

const VIEW_LABELS: Record<View, string> = { html: 'HTML', text: 'Text', source: 'HTML source' };

const view = ref<View>('html');

const views = computed<View[]>(() => {
    const available: View[] = [];
    if (props.mail.html !== null) available.push('html');
    if (props.mail.text !== null) available.push('text');
    if (props.mail.html !== null) available.push('source');
    return available;
});

/** Recipient rows of the envelope, skipping the empty ones. */
const envelope = computed<Array<[string, string]>>(() => {
    const mail = props.mail;
    const rows: Array<[string, string[] | string | null | undefined]> = [
        ['from', mail.from],
        ['to', mail.to],
        ['cc', mail.cc],
        ['bcc', mail.bcc],
        ['reply to', mail.reply_to],
        ['built by', mail.source],
        ['sent by', mail.origin],
        ['mailer', mail.mailer],
    ];
    return rows
        .map(([label, value]): [string, string] => [label, Array.isArray(value) ? value.join(', ') : (value ?? '')])
        .filter(([, value]) => value !== '');
});

const sentAt = computed<string>(() => {
    if (props.mail.time_ms !== undefined) return `sent at ${props.mail.time_ms} ms`;
    return props.mail.sent_at ? new Date(props.mail.sent_at).toLocaleString() : '';
});

// Keep the chosen view when switching mail, unless that mail has no such body.
watch(views, (available) => {
    if (available.length > 0 && !available.includes(view.value)) view.value = available[0];
}, { immediate: true });
</script>

<template>
    <article class="detail">
        <header class="detail__header">
            <strong class="detail__subject">{{ mail.subject || '(no subject)' }}</strong>
            <span class="muted">{{ sentAt }}</span>
        </header>

        <dl class="envelope">
            <template v-for="[label, value] in envelope" :key="label">
                <dt>{{ label }}</dt>
                <dd>{{ value }}</dd>
            </template>
        </dl>

        <ul v-if="mail.attachments.length" class="attachments">
            <li
                v-for="(file, i) in mail.attachments"
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
            v-if="view === 'html' && mail.html !== null"
            class="preview"
            sandbox=""
            :srcdoc="mail.html"
            title="HTML body"
        />
        <pre v-else-if="view === 'text' && mail.text !== null" class="body">{{ mail.text }}</pre>
        <pre v-else-if="view === 'source' && mail.html !== null" class="body">{{ mail.html }}</pre>
        <p v-else class="muted">This mail has no body.</p>
    </article>
</template>

<style scoped>
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
