<script setup lang="ts">
import { computed, ref } from 'vue';
import ValueTree from './ValueTree.vue';
import type { InertiaDeferredProps, InertiaPage, SnipNode } from './types';
import { jsValueToNode } from './dumper';

const props = defineProps<{
    page: InertiaPage;
    deferred: InertiaDeferredProps;
}>();

/** The shared prop the package ships its own payload in; it is the panel's data, not the page's. */
const SNIP_PROP = '_snip';

type Badge = { text: string; kind: 'deferred' | 'pending' | 'merge'; title: string };
type Row = { key: string; node: SnipNode | null; badges: Badge[] };

const MERGE_KINDS: Array<[string, keyof InertiaPage, string]> = [
    ['merge', 'mergeProps', 'Appended to the current value on partial reloads'],
    ['prepend', 'prependProps', 'Prepended to the current value on partial reloads'],
    ['deep merge', 'deepMergeProps', 'Deep-merged into the current value on partial reloads'],
];

const expanded = ref<Set<string>>(new Set());

const deferredGroups = computed<Map<string, string>>(() => {
    const groups = new Map<string, string>();
    for (const [group, names] of Object.entries(props.deferred)) {
        names.forEach((name) => groups.set(name, group));
    }
    return groups;
});

const rows = computed<Row[]>(() => {
    const values = props.page.props;
    const keys = Object.keys(values).filter((key) => key !== SNIP_PROP);
    const pending = [...deferredGroups.value.keys()].filter((key) => !(key in values));

    return [...keys, ...pending].map((key) => ({
        key,
        node: key in values ? jsValueToNode(values[key]) : null,
        badges: badgesFor(key, key in values),
    }));
});

const pendingCount = computed<number>(() => rows.value.filter((row) => row.node === null).length);
const loadedCount = computed<number>(() => rows.value.length - pendingCount.value);

function badgesFor(key: string, loaded: boolean): Badge[] {
    const badges: Badge[] = [];
    const group = deferredGroups.value.get(key);

    if (group !== undefined) {
        const suffix = group === 'default' ? '' : ` · ${group}`;
        badges.push(loaded
            ? { text: `deferred${suffix}`, kind: 'deferred', title: 'Loaded by a follow-up request after the page rendered' }
            : { text: `deferred${suffix} · pending`, kind: 'pending', title: 'Not loaded yet: the follow-up request has not finished' });
    }

    for (const [label, field, title] of MERGE_KINDS) {
        for (const path of (props.page[field] as string[] | undefined) ?? []) {
            if (path !== key && !path.startsWith(`${key}.`)) continue;

            const nested = path === key ? '' : ` ${path.slice(key.length + 1)}`;
            const matchOn = (props.page.matchPropsOn ?? [])
                .filter((m) => m.startsWith(`${path}.`))
                .map((m) => m.slice(path.length + 1));
            const matchNote = matchOn.length ? `, matching items on ${matchOn.join(', ')}` : '';

            badges.push({ text: `${label}${nested}`, kind: 'merge', title: `${title}${matchNote}` });
        }
    }

    return badges;
}

function expandable(row: Row): boolean {
    return (row.node?.children?.length ?? 0) > 0;
}

function toggle(row: Row): void {
    if (!expandable(row)) return;

    const next = new Set(expanded.value);
    next.has(row.key) ? next.delete(row.key) : next.add(row.key);
    expanded.value = next;
}
</script>

<template>
    <div class="wrap">
        <header class="head">
            <span class="component-chip" :title="'Page component'">{{ page.component }}</span>
            <code class="url" :title="page.url">{{ page.url }}</code>
            <span v-if="page.version" class="muted">version: <code>{{ page.version }}</code></span>
            <span class="muted">
                {{ loadedCount }} prop{{ loadedCount === 1 ? '' : 's' }}<span v-if="pendingCount"> · {{ pendingCount }} deferred pending</span>
            </span>
        </header>

        <div v-if="rows.length === 0" class="empty">This page has no props.</div>

        <ul v-else class="props">
            <li v-for="row in rows" :key="row.key" class="prop">
                <button
                    type="button"
                    class="prop__head"
                    :disabled="!expandable(row)"
                    :aria-expanded="expandable(row) ? expanded.has(row.key) : undefined"
                    @click="toggle(row)"
                >
                    <span class="caret" :class="{ 'caret--open': expanded.has(row.key), 'caret--hidden': !expandable(row) }">▸</span>
                    <span class="key">{{ row.key }}</span>
                    <span
                        v-for="badge in row.badges"
                        :key="badge.text"
                        class="badge"
                        :class="`badge--${badge.kind}`"
                        :title="badge.title"
                    >{{ badge.text }}</span>
                    <span v-if="row.node" class="preview" :title="row.node.preview">{{ row.node.preview }}</span>
                    <span v-else class="preview preview--pending">not loaded</span>
                </button>

                <div v-if="row.node && expanded.has(row.key)" class="prop__body">
                    <ValueTree
                        v-for="(child, index) in row.node.children"
                        :key="index"
                        :node="child"
                        :initially-open="false"
                        :depth="1"
                    />
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

.head {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--snip-border);
    font-size: 12px;
}

.component-chip {
    background: var(--snip-accent-strong);
    color: #ffffff;
    border-radius: 9999px;
    padding: 2px 10px;
    font-size: 11px;
    font-weight: 600;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}

.url {
    color: var(--snip-text-strong);
    overflow-wrap: anywhere;
}

.muted {
    color: var(--snip-text-muted);
    font-size: 12px;
}

code {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    background: var(--snip-surface-2);
    padding: 1px 4px;
    border-radius: 3px;
}

.empty {
    color: var(--snip-text-faint);
    text-align: center;
    padding: 28px 0;
    font-size: 13px;
}

.props {
    list-style: none;
    margin: 0;
    padding: 0;
    background: var(--snip-surface);
    border: 1px solid var(--snip-border);
    border-radius: 8px;
    overflow: hidden;
}

.prop + .prop {
    border-top: 1px solid var(--snip-border-soft);
}

.prop__head {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    min-width: 0;
    padding: 7px 12px;
    background: transparent;
    border: none;
    color: inherit;
    font: inherit;
    font-size: 12px;
    text-align: left;
    cursor: pointer;
}

.prop__head:disabled {
    cursor: default;
}

.prop__head:not(:disabled):hover {
    background: var(--snip-hover);
}

.prop__head:focus-visible {
    outline: 2px solid var(--snip-accent);
    outline-offset: -2px;
}

.caret {
    color: var(--snip-text-faint);
    font-size: 10px;
    width: 12px;
    text-align: center;
    transition: transform 0.12s ease;
    flex-shrink: 0;
}

.caret--open {
    transform: rotate(90deg);
}

.caret--hidden {
    visibility: hidden;
}

.key {
    color: var(--snip-text-strong);
    font-weight: 600;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    flex-shrink: 0;
}

.badge {
    flex-shrink: 0;
    border-radius: 9999px;
    padding: 0 7px;
    font-size: 10px;
    line-height: 16px;
    font-weight: 600;
    letter-spacing: 0.02em;
    border: 1px solid currentColor;
    cursor: help;
}

/* Filled with dark text, like the queue state badges: these tokens are too light for text on the light theme. */
.badge--deferred {
    background: var(--snip-ok);
    border-color: var(--snip-ok);
    color: #000000;
}

.badge--pending {
    background: var(--snip-warn);
    border-color: var(--snip-warn);
    color: #000000;
}

.badge--merge {
    color: var(--snip-accent);
}

.preview {
    flex: 1;
    min-width: 0;
    text-align: right;
    color: var(--snip-text-faint);
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 11px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.preview--pending {
    font-family: inherit;
    font-style: italic;
}

.prop__body {
    padding: 2px 12px 8px 26px;
}
</style>
