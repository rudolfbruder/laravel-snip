<script setup lang="ts">
import { computed } from 'vue';
import type { SnipMilestone } from './types';
import { shortenFile } from './format';

const props = defineProps<{
    milestones: SnipMilestone[];
}>();

type RenderRow = SnipMilestone & {
    index: number;
    delta_ms: number | null;
};

const rows = computed<RenderRow[]>(() => {
    let prev: number | null = null;
    return props.milestones.map((m, i) => {
        const delta = prev === null ? null : Math.round((m.time_ms - prev) * 100) / 100;
        prev = m.time_ms;
        return { ...m, index: i + 1, delta_ms: delta };
    });
});
</script>

<template>
    <div class="wrap">
        <div v-if="milestones.length === 0" class="empty">No milestones recorded.</div>

        <ul v-else class="list">
            <li v-for="row in rows" :key="row.index" class="row">
                <div class="row__head">
                    <span class="num">#{{ row.index }}</span>
                    <span class="label">{{ row.label }}</span>
                    <span class="ms">{{ row.time_ms }} ms</span>
                </div>
                <div class="row__meta">
                    {{ shortenFile(row.file) }}<span v-if="row.line">:{{ row.line }}</span>
                    <span v-if="row.delta_ms !== null" class="delta">Δ +{{ row.delta_ms }} ms</span>
                </div>
            </li>
        </ul>
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

.list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.row {
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding: 10px 12px;
    background: var(--snip-surface);
    border: 1px solid var(--snip-border);
    border-radius: 8px;
}

.row__head {
    display: flex;
    align-items: baseline;
    gap: 10px;
}

.num {
    color: var(--snip-text-fainter);
    font-size: 11px;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    flex-shrink: 0;
    min-width: 28px;
}

.label {
    flex: 1;
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
    color: var(--snip-accent);
}

.row__meta {
    display: flex;
    align-items: center;
    gap: 10px;
    color: var(--snip-text-faint);
    font-size: 11px;
    word-break: break-all;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    padding-left: 38px;
}

.delta {
    margin-left: auto;
    color: var(--snip-text-muted);
    background: var(--snip-chip-bg);
    padding: 1px 8px;
    border-radius: 9999px;
    font-size: 10px;
    font-variant-numeric: tabular-nums;
    flex-shrink: 0;
}
</style>
