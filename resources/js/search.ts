import type { SnipNode } from './types';

export type TextSegment = { text: string; hit: boolean };

/** Case-insensitive substring test; `term` is expected lowercased. */
export function matches(text: string | undefined, term: string): boolean {
    return text !== undefined && text.toLowerCase().includes(term);
}

/**
 * Keeps the parts of a tree that match `term` (lowercased): nodes whose key matches, with all their
 * children, and leaves whose value matches, with the path down to them. Nodes on the way to a match
 * deeper down come back with `open: true`. Returns null when nothing in the tree matches.
 */
export function filterTree(node: SnipNode, term: string): SnipNode | null {
    const keyHit = matches(node.key, term);
    const children = node.children ?? [];

    if (children.length === 0) {
        return keyHit || matches(node.preview, term) ? node : null;
    }

    const filtered = children.map((child) => filterTree(child, term));
    const childHit = filtered.some((child) => child !== null);

    if (!keyHit && !childHit) return null;

    return {
        ...node,
        children: keyHit ? children.map((child, i) => filtered[i] ?? child) : filtered.filter((child): child is SnipNode => child !== null),
        open: childHit || undefined,
    };
}

/** Splits `text` around each case-insensitive occurrence of `term`, for highlighting. */
export function highlightSegments(text: string, term: string | undefined): TextSegment[] {
    if (!term) return [{ text, hit: false }];

    const lower = text.toLowerCase();
    const segments: TextSegment[] = [];
    let from = 0;

    for (let at = lower.indexOf(term); at !== -1; at = lower.indexOf(term, from)) {
        if (at > from) segments.push({ text: text.slice(from, at), hit: false });
        segments.push({ text: text.slice(at, at + term.length), hit: true });
        from = at + term.length;
    }
    if (from < text.length) segments.push({ text: text.slice(from), hit: false });

    return segments;
}
