<script setup lang="ts">
import { computed } from 'vue';
import { canCopyValues, copiedTarget, copyValue } from './clipboard';

/**
 * Copies a value. It stays hidden until the parent reveals it, usually while its row is hovered:
 * the parent styles this root element, e.g. `.row:hover .copy-value { visibility: visible }`.
 */
const props = defineProps<{
    /** Identifies this button among all copy buttons, to show which one copied. */
    target: unknown;
    value: unknown;
    /** What is copied, for the tooltip and screen readers. */
    label: string;
}>();

const copied = computed<boolean>(() => copiedTarget.value === props.target);
</script>

<template>
    <button
        v-if="canCopyValues"
        type="button"
        class="copy-value"
        :class="{ 'copy-value--done': copied }"
        :title="copied ? 'Copied!' : `Copy ${label}`"
        :aria-label="`Copy ${label}`"
        @click.stop="copyValue(target, value)"
    >
        <span v-if="copied" aria-hidden="true">✓</span>
        <svg v-else viewBox="0 0 16 16" width="12" height="12" aria-hidden="true"><rect x="5" y="5" width="9" height="9" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.5" /><path d="M11 5V3.5A1.5 1.5 0 0 0 9.5 2h-6A1.5 1.5 0 0 0 2 3.5v6A1.5 1.5 0 0 0 3.5 11H5" fill="none" stroke="currentColor" stroke-width="1.5" /></svg>
    </button>
</template>

<style scoped>
.copy-value {
    flex-shrink: 0;
    align-self: center;
    width: 20px;
    height: 20px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    background: transparent;
    border: 1px solid transparent;
    border-radius: 4px;
    color: var(--snip-text-muted);
    font: inherit;
    font-size: 12px;
    line-height: 1;
    cursor: pointer;
    visibility: hidden;
}

.copy-value:focus-visible,
.copy-value--done {
    visibility: visible;
}

/* Touch screens cannot hover to reveal it. */
@media (hover: none) {
    .copy-value {
        visibility: visible;
    }
}

.copy-value:hover {
    border-color: var(--snip-border);
    color: var(--snip-text-strong);
}

.copy-value:focus-visible {
    outline: 2px solid var(--snip-accent);
    outline-offset: -2px;
}

.copy-value--done {
    color: var(--snip-ok);
}
</style>
