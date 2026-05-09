<script setup lang="ts">
import { computed, ref } from 'vue';
import type { SnipTiming } from './types';
import { durationColor, shortenFile } from './format';

const props = defineProps<{
    timings: SnipTiming[];
}>();

type SortMode = 'duration' | 'chronological';

const sortMode = ref<SortMode>('duration');

const sorted = computed<SnipTiming[]>(() => {
    const copy = props.timings.slice();
    if (sortMode.value === 'duration') {
        copy.sort((a, b) => b.duration_ms - a.duration_ms);
    } else {
        copy.sort((a, b) => a.start_ms - b.start_ms);
    }
    return copy;
});

const maxDuration = computed<number>(() => {
    return props.timings.reduce((max, t) => Math.max(max, t.duration_ms), 0) || 1;
});

function barWidth(ms: number): string {
    return Math.max(2, (ms / maxDuration.value) * 100) + '%';
}

function toggleSort() {
    sortMode.value = sortMode.value === 'duration' ? 'chronological' : 'duration';
}
</script>

<template>
    <div class="wrap">
        <div v-if="timings.length === 0" class="empty">No timings recorded.</div>

        <template v-else>
            <header class="head">
                <span class="muted">{{ timings.length }} entries · scaled against slowest ({{ maxDuration }} ms)</span>
                <button type="button" class="sort" @click="toggleSort">
                    Sort: {{ sortMode === 'duration' ? 'duration ↓' : 'chronological' }}
                </button>
            </header>

            <ul class="list">
                <li v-for="(t, i) in sorted" :key="i" class="row">
                    <div class="row__head">
                        <span class="label">{{ t.label }}</span>
                        <span class="ms" :style="{ color: durationColor(t.duration_ms) }">{{ t.duration_ms }} ms</span>
                    </div>
                    <div class="row__meta">
                        {{ shortenFile(t.file) }}<span v-if="t.line">:{{ t.line }}</span>
                        <span class="muted"> · starts at {{ t.start_ms }} ms</span>
                    </div>
                    <div class="bar-track">
                        <div
                            class="bar-fill"
                            :style="{
                                width: barWidth(t.duration_ms),
                                backgroundColor: durationColor(t.duration_ms),
                            }"
                        />
                    </div>
                </li>
            </ul>
        </template>
    </div>
</template>

<style scoped>
.wrap {
    display: flex;
    flex-direction: column;
    gap: 12px;
    color: var(--snip-text);
}

.empty {
    color: var(--snip-text-faint);
    text-align: center;
    padding: 28px 0;
    font-size: 13px;
}

.head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--snip-border);
    font-size: 12px;
}

.muted {
    color: var(--snip-text-muted);
}

.sort {
    background: transparent;
    border: 1px solid var(--snip-border);
    color: var(--snip-text);
    border-radius: 6px;
    padding: 4px 10px;
    font-size: 11px;
    cursor: pointer;
    font-family: inherit;
}

.sort:hover {
    background: var(--snip-surface-2);
}

.list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.row {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 10px 12px;
    background: var(--snip-surface);
    border: 1px solid var(--snip-border);
    border-radius: 8px;
}

.row__head {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 16px;
}

.label {
    font-weight: 600;
    color: var(--snip-text-strong);
    word-break: break-all;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 12px;
}

.ms {
    flex-shrink: 0;
    font-variant-numeric: tabular-nums;
    font-size: 12px;
    font-weight: 600;
}

.row__meta {
    color: var(--snip-text-faint);
    font-size: 11px;
    word-break: break-all;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}

.bar-track {
    margin-top: 6px;
    height: 6px;
    background: var(--snip-border-soft);
    border-radius: 9999px;
    overflow: hidden;
}

.bar-fill {
    height: 100%;
    border-radius: 9999px;
    transition: width 0.18s ease;
}
</style>
