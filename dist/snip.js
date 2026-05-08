/**
 * laravel-snip placeholder bundle.
 *
 * The real bundle is produced by Vite from resources/js/main.ts and bundles
 * a Vue 3 runtime + the <laravel-snip> custom element with Shadow DOM.
 *
 * Run the following inside packages/rudolfbruder/laravel-snip (or wherever
 * Composer installed the package):
 *
 *     npm install
 *     npm run build
 *
 * That overwrites this file with the real ~35 KB gzipped bundle. Until then
 * the panel will not render.
 */
(function () {
    if (typeof window === 'undefined') return;
    if (window.customElements && window.customElements.get('laravel-snip')) return;

    console.warn('[laravel-snip] dist/snip.js is a placeholder. Run `npm install && npm run build` inside the package directory to produce the real bundle.');

    if (window.customElements) {
        window.customElements.define(
            'laravel-snip',
            class extends HTMLElement {
                connectedCallback() {
                    this.style.display = 'none';
                }
            }
        );
    }
})();
