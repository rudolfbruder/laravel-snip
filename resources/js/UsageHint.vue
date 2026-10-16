<script setup lang="ts">
import { computed } from 'vue';
import { useClipboard } from '@vueuse/core';

/** Empty-tab placeholder: says what the tab shows and gives a snippet to paste into the app. */
const props = defineProps<{
    message: string;
    code: string;
}>();

/** `//` comments in PHP, `#` comments in `.env` and shell snippets. */
const lines = computed(() => props.code.split('\n').map((text) => ({ text, comment: /^\s*(\/\/|#)/.test(text) })));

const { copy, copied, isSupported } = useClipboard({ legacy: true, copiedDuring: 1500 });
</script>

<template>
    <section class="hint">
        <header class="hint__header">
            <div>
                <h4 class="hint__title">Get started</h4>
                <p class="hint__message">{{ message }}</p>
            </div>
            <button v-if="isSupported" class="hint__copy" type="button" title="Copy to clipboard" @click="copy(code)">
                {{ copied ? '✓ copied' : 'copy' }}
            </button>
        </header>
        <pre class="hint__code"><code><span v-for="(line, i) in lines" :key="i" :class="{ 'hint__comment': line.comment }">{{ line.text }}{{ i < lines.length - 1 ? '\n' : '' }}</span></code></pre>
    </section>
</template>

<style scoped>
.hint {
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding: 12px 14px;
    background: var(--snip-surface);
    border: 1px solid var(--snip-border);
    border-radius: 8px;
}

.hint__header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
}

.hint__title {
    margin: 0 0 2px;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--snip-text-muted);
}

.hint__message {
    margin: 0;
    font-size: 12px;
    color: var(--snip-text);
}

.hint__copy {
    flex-shrink: 0;
    background: transparent;
    border: 1px solid var(--snip-border);
    color: var(--snip-text-muted);
    border-radius: 6px;
    padding: 3px 10px;
    font-size: 11px;
    font-family: inherit;
    cursor: pointer;
}

.hint__copy:hover {
    background: var(--snip-surface-2);
    color: var(--snip-text);
}

.hint__comment {
    color: var(--snip-text-faint);
}

/* Wrapped rather than scrolled: the shadow root resets color-scheme, so a scrollbar would render light. */
.hint__code {
    margin: 0;
    padding: 10px 12px;
    background: var(--snip-bg);
    border: 1px solid var(--snip-border-soft);
    border-radius: 6px;
    color: var(--snip-text);
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 11.5px;
    line-height: 1.6;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}
</style>
