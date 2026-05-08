<script setup lang="ts">
import { computed, getCurrentInstance, onMounted, ref } from 'vue';
import ValueTree from './ValueTree.ce.vue';
import type { SnipEntry } from './types';

const open = ref(false);
const entries = ref<SnipEntry[]>([]);
const activeIndex = ref(0);

onMounted(() => {
    const instance = getCurrentInstance();
    const host = instance?.proxy?.$el?.parentNode?.host as HTMLElement | undefined;

    if (!host) {
        return;
    }

    const raw = host.getAttribute('data-payload') ?? '[]';

    try {
        entries.value = JSON.parse(raw) as SnipEntry[];
    } catch (e) {
        entries.value = [];
        console.error('[laravel-snip] failed to parse payload', e);
    }
});

const activeEntry = computed<SnipEntry | null>(() => entries.value[activeIndex.value] ?? null);

function shortenFile(file: string | null): string {
    if (!file) return '';
    const parts = file.split('/');
    return parts.slice(-3).join('/');
}
</script>

<template>
    <div v-if="entries.length > 0" class="snip-root">
        <button
            class="toggle"
            :class="{ 'toggle--active': open }"
            type="button"
            @click="open = !open"
        >
            <span class="dot" />
            <span class="label">snip</span>
            <span class="count">{{ entries.length }}</span>
        </button>

        <div v-if="open" class="panel" role="dialog" aria-label="Laravel Snip">
            <div class="panel__header">
                <strong>Laravel Snip</strong>
                <span class="muted">{{ entries.length }} entries</span>
                <button class="close" type="button" @click="open = false">×</button>
            </div>

            <div class="panel__body">
                <aside class="entries">
                    <button
                        v-for="(entry, index) in entries"
                        :key="index"
                        class="entry"
                        :class="{ 'entry--active': index === activeIndex }"
                        type="button"
                        @click="activeIndex = index"
                    >
                        <span class="entry__label">
                            {{ entry.label || `#${index + 1}` }}
                        </span>
                        <span class="entry__meta">
                            {{ shortenFile(entry.file) }}<span v-if="entry.line">:{{ entry.line }}</span>
                        </span>
                        <span class="entry__time">{{ entry.time_ms }} ms</span>
                    </button>
                </aside>

                <section class="value">
                    <div v-if="activeEntry" class="value__inner">
                        <header class="value__header">
                            <strong v-if="activeEntry.label">{{ activeEntry.label }}</strong>
                            <span class="muted">
                                {{ shortenFile(activeEntry.file) }}<span v-if="activeEntry.line">:{{ activeEntry.line }}</span>
                                · {{ activeEntry.time_ms }} ms
                            </span>
                        </header>
                        <ValueTree :node="activeEntry.value" :initially-open="true" :depth="0" />
                    </div>
                </section>
            </div>
        </div>
    </div>
</template>

<style scoped>
:host,
.snip-root {
    all: initial;
    color: #e5e7eb;
    font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    font-size: 13px;
    color-scheme: dark;
}

.snip-root {
    position: fixed;
    bottom: 16px;
    right: 16px;
    z-index: 2147483647;
}

.toggle {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #111827;
    color: #e5e7eb;
    border: 1px solid #1f2937;
    border-radius: 9999px;
    padding: 8px 14px;
    cursor: pointer;
    box-shadow: 0 12px 28px rgba(0, 0, 0, 0.35);
    transition: transform 0.12s ease, background 0.12s ease;
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.toggle:hover {
    background: #1f2937;
    transform: translateY(-1px);
}

.toggle--active {
    background: #2563eb;
    border-color: #1d4ed8;
}

.dot {
    width: 8px;
    height: 8px;
    border-radius: 9999px;
    background: #34d399;
    box-shadow: 0 0 0 4px rgba(52, 211, 153, 0.18);
}

.label {
    color: #f9fafb;
}

.count {
    background: rgba(255, 255, 255, 0.12);
    color: #f9fafb;
    border-radius: 9999px;
    padding: 1px 8px;
    font-size: 11px;
    line-height: 1.4;
}

.panel {
    position: absolute;
    bottom: 48px;
    right: 0;
    width: min(880px, 92vw);
    height: min(560px, 70vh);
    background: #0b1220;
    border: 1px solid #1f2937;
    border-radius: 12px;
    box-shadow: 0 24px 60px rgba(0, 0, 0, 0.5);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.panel__header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    background: #0f172a;
    border-bottom: 1px solid #1f2937;
}

.panel__header strong {
    color: #f9fafb;
}

.muted {
    color: #94a3b8;
    font-size: 12px;
}

.close {
    margin-left: auto;
    background: transparent;
    color: #94a3b8;
    border: none;
    font-size: 22px;
    line-height: 1;
    cursor: pointer;
    padding: 0 4px;
}

.close:hover {
    color: #f9fafb;
}

.panel__body {
    display: grid;
    grid-template-columns: 240px 1fr;
    flex: 1;
    min-height: 0;
}

.entries {
    border-right: 1px solid #1f2937;
    overflow-y: auto;
    background: #0f172a;
}

.entry {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 2px;
    width: 100%;
    padding: 10px 14px;
    background: transparent;
    border: none;
    border-bottom: 1px solid #1f2937;
    color: #e5e7eb;
    text-align: left;
    cursor: pointer;
    font-family: inherit;
    font-size: 12px;
}

.entry:hover {
    background: #111827;
}

.entry--active {
    background: #1e293b;
}

.entry__label {
    font-weight: 600;
    color: #f9fafb;
    word-break: break-all;
}

.entry__meta {
    color: #64748b;
    font-size: 11px;
    word-break: break-all;
}

.entry__time {
    color: #475569;
    font-size: 10px;
}

.value {
    overflow: auto;
    padding: 14px 18px;
}

.value__inner {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.value__header {
    display: flex;
    align-items: center;
    gap: 10px;
    padding-bottom: 10px;
    border-bottom: 1px solid #1f2937;
}
</style>
