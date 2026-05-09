<script setup lang="ts">
import { computed, ref } from 'vue';
import ValueTree from './ValueTree.vue';
import type { DataLayerEvent } from './types';
import { jsValueToNode } from './dumper';
import { isCustomEvent } from './dataLayer';

const props = defineProps<{
    events: DataLayerEvent[];
}>();

const expanded = ref<Record<number, boolean>>({});
const showAll = ref(false);

function eventName(payload: unknown): string {
    if (payload && typeof payload === 'object' && 'event' in (payload as Record<string, unknown>)) {
        const v = (payload as Record<string, unknown>).event;
        if (typeof v === 'string') return v;
    }
    if (Array.isArray(payload)) {
        const first = payload[0];
        if (typeof first === 'string') return first;
    }
    if (typeof payload === 'string') return payload;
    return '(no event key)';
}

function toggle(index: number): void {
    expanded.value = { ...expanded.value, [index]: !expanded.value[index] };
}

const rows = computed(() => {
    const all = props.events.map((e) => ({
        ...e,
        name: eventName(e.payload),
        node: jsValueToNode(e.payload),
        isCustom: isCustomEvent(e.payload),
    }));

    return showAll.value ? all : all.filter((r) => r.isCustom);
});

const hiddenCount = computed(() => props.events.length - props.events.filter((e) => isCustomEvent(e.payload)).length);
</script>

<template>
    <div class="wrap">
        <header v-if="events.length > 0" class="head">
            <span class="muted">
                {{ rows.length }} {{ showAll ? 'total' : 'site events' }}
                <span v-if="!showAll && hiddenCount > 0">· {{ hiddenCount }} GTM-internal hidden</span>
            </span>
            <button type="button" class="toggle-btn" @click="showAll = !showAll">
                {{ showAll ? 'Hide gtm.* + no-event' : 'Show all' }}
            </button>
        </header>

        <div v-if="events.length === 0" class="empty">
            No dataLayer events captured yet.
            <div class="empty__hint">If GTM isn't loaded on this page, this tab stays empty.</div>
        </div>

        <div v-else-if="rows.length === 0" class="empty">
            No site-defined events yet.
            <div class="empty__hint">Only GTM-internal pushes captured. Click "Show all" to see them.</div>
        </div>

        <ul v-else class="list">
            <li v-for="row in rows" :key="row.index" class="row">
                <button class="row__head" type="button" @click="toggle(row.index)">
                    <span class="caret">{{ expanded[row.index] ? '▾' : '▸' }}</span>
                    <span class="num">#{{ row.index + 1 }}</span>
                    <span class="name">{{ row.name }}</span>
                    <span class="ms">+{{ row.pushed_at_ms }} ms</span>
                </button>
                <div v-if="expanded[row.index]" class="row__body">
                    <ValueTree :node="row.node" :initially-open="true" :depth="0" />
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

.empty__hint {
    margin-top: 6px;
    color: var(--snip-text-fainter);
    font-size: 11px;
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

.toggle-btn {
    background: transparent;
    border: 1px solid var(--snip-border);
    color: var(--snip-text);
    border-radius: 6px;
    padding: 4px 10px;
    font-size: 11px;
    cursor: pointer;
    font-family: inherit;
}

.toggle-btn:hover {
    background: var(--snip-surface-2);
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
    background: var(--snip-surface);
    border: 1px solid var(--snip-border);
    border-radius: 8px;
    overflow: hidden;
}

.row__head {
    display: flex;
    align-items: baseline;
    gap: 10px;
    width: 100%;
    padding: 10px 12px;
    background: transparent;
    border: none;
    color: var(--snip-text);
    text-align: left;
    cursor: pointer;
    font-family: inherit;
    font-size: 12px;
}

.row__head:hover {
    background: var(--snip-surface-2);
}

.caret {
    color: var(--snip-text-muted);
    width: 12px;
    flex-shrink: 0;
}

.num {
    color: var(--snip-text-fainter);
    font-size: 11px;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    flex-shrink: 0;
    min-width: 32px;
}

.name {
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
    font-size: 11px;
    color: var(--snip-text-muted);
    background: var(--snip-chip-bg);
    padding: 1px 8px;
    border-radius: 9999px;
}

.row__body {
    padding: 8px 14px 12px 30px;
    border-top: 1px solid var(--snip-border);
    background: var(--snip-bg);
}
</style>
