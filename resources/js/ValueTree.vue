<script setup lang="ts">
import { ref, computed } from 'vue';
import type { SnipNode } from './types';

const props = defineProps<{
    node: SnipNode;
    initiallyOpen?: boolean;
    depth?: number;
}>();

const open = ref(props.initiallyOpen ?? (props.depth ?? 0) < 1);

const hasChildren = computed(() => Array.isArray(props.node.children) && props.node.children.length > 0);

const typeColor = computed(() => {
    switch (props.node.type) {
        case 'string':
            return '#a3e635';
        case 'int':
        case 'float':
            return '#60a5fa';
        case 'bool':
            return '#f472b6';
        case 'null':
            return '#9ca3af';
        case 'array':
        case 'collection':
            return '#fbbf24';
        case 'object':
        case 'model':
            return '#c084fc';
        case 'enum':
            return '#34d399';
        case 'datetime':
            return '#22d3ee';
        case 'closure':
            return '#fb923c';
        case 'redacted':
            return '#ef4444';
        case 'circular':
        case 'truncated':
        case 'error':
            return '#f87171';
        default:
            return '#e5e7eb';
    }
});

function toggle() {
    if (hasChildren.value) {
        open.value = !open.value;
    }
}
</script>

<template>
    <div class="row">
        <div class="header" :class="{ clickable: hasChildren }" @click="toggle">
            <span v-if="hasChildren" class="caret">{{ open ? '▾' : '▸' }}</span>
            <span v-else class="caret-spacer"></span>

            <span v-if="node.key !== undefined" class="key">{{ node.key }}</span>
            <span v-if="node.key !== undefined" class="colon">:</span>

            <span class="preview" :style="{ color: typeColor }">{{ node.preview }}</span>
            <span class="type">{{ node.type }}</span>
        </div>

        <div v-if="open && hasChildren" class="children">
            <ValueTree
                v-for="(child, index) in node.children"
                :key="index"
                :node="child"
                :depth="(depth ?? 0) + 1"
            />
        </div>
    </div>
</template>

<style scoped>
.row {
    display: flex;
    flex-direction: column;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 12px;
    line-height: 1.6;
    color: var(--snip-text);
}

.header {
    display: flex;
    align-items: baseline;
    gap: 6px;
    padding: 2px 4px;
    border-radius: 3px;
}

.header.clickable {
    cursor: pointer;
}

.header.clickable:hover {
    background: var(--snip-hover);
}

.caret {
    width: 12px;
    color: var(--snip-text-muted);
}

.caret-spacer {
    width: 12px;
    display: inline-block;
}

.key {
    color: var(--snip-text-strong);
    font-weight: 600;
}

.colon {
    color: var(--snip-text-faint);
    margin-left: -4px;
}

.preview {
    word-break: break-all;
    white-space: pre-wrap;
}

.type {
    margin-left: auto;
    color: var(--snip-text-faint);
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    padding-left: 12px;
    flex-shrink: 0;
}

.children {
    border-left: 1px solid var(--snip-border-soft);
    margin-left: 6px;
    padding-left: 10px;
}
</style>
