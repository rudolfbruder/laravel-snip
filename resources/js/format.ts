export function shortenFile(file: string | null): string {
    if (!file) return '';
    return file.split('/').slice(-3).join('/');
}

export function durationColor(ms: number): string {
    if (ms < 50) return 'var(--snip-ok)';
    if (ms < 200) return 'var(--snip-warn)';
    return 'var(--snip-bad)';
}

export function formatBytes(bytes: number | null | undefined): string {
    if (bytes === null || bytes === undefined) return '';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    if (bytes < 1024 * 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
    return `${(bytes / (1024 * 1024 * 1024)).toFixed(2)} GB`;
}
