/**
 * Headers that get a POST or DELETE past Laravel's CSRF check. The framework mirrors the token into
 * the readable XSRF-TOKEN cookie on every web response, and accepts it back as X-XSRF-TOKEN.
 */
export function csrfHeaders(): Record<string, string> {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    if (match) return { 'X-XSRF-TOKEN': decodeURIComponent(match[1]) };

    const meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]');
    return meta ? { 'X-CSRF-TOKEN': meta.content } : {};
}
