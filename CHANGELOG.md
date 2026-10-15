# Changelog

All notable changes to `laravel-snip` will be documented in this file.

## [Unreleased]

## [v0.3.1] - 2026-10-15

### Added
- The Inertia tab has a search box: it keeps the props whose name, nested keys or values contain the text, opens the path down to each match and highlights it. The **×** button or **Esc** clears it, and clearing brings back what was open before.
- Each array, object and string prop in the Inertia tab shows its size (the length of its JSON in the response), and the header shows the total of all props.

### Changed
- The tabs always stay on one line next to the title. Tabs that do not fit scroll sideways (with the mouse wheel too), a fade marks the edge with more tabs, and the active tab is scrolled into view. This replaces the separate row narrow panels used in v0.3.0.
- The Inertia tab no longer shows the page's asset version, which is rarely useful when debugging.

### Fixed
- On a narrow screen the panel ran off the left edge, because its 480px minimum width ignored the viewport. Its minimum and maximum size now leave the 16px corner gap free.

## [v0.3.0] - 2026-10-15

### Added
- **Inertia** tab, shown on Inertia pages: the current page's component, URL, asset version and props as expandable trees. Props carry **deferred** (with their group, and **pending** until loaded), **merge**, **prepend** and **deep merge** badges. It follows SPA navigation, back/forward and partial reloads, and reads the page object Inertia already sends, so nothing is added to the response.
- The playground has an **Inertia** switch that renders a fake Inertia page, with buttons that load its deferred props, load more orders (a merging partial reload) and visit another page.

### Changed
- The panel header's reset button also puts a resized panel back to its default size and forgets the remembered size. It now shows after a resize alone, too.
- The panel can no longer be resized below 480×440px (or the viewport, when that is smaller), so the header and the settings menu always fit.

### Fixed
- In a narrow panel the tabs pushed the reset, settings and close buttons out of view. The tabs now move to their own row under the title and wrap if needed, and the buttons stay put.
- The settings menu could run past the bottom of a short panel, cutting off its last options. It is now capped to the room in the panel and scrolls.
- Expanded values in the Cache, DataLayer and Queue tabs were drawn as nested bordered cards, because the value tree picked up the list's row styling. They render as the plain tree used in the Snips tab again.

## [v0.2.5] - 2026-10-14

### Added
- Empty Snips, Timings and Milestones tabs, and a Profiler tab with no measured steps, show a **Get started** card with the `\Snip::` calls to add (comments dimmed) and a **copy** button, instead of a blank area.
- Once the panel has been dragged away from its corner, a **reset position** button appears in the panel header next to the settings gear. It puts the panel back next to the pill and forgets the dragged spot; hovering or focusing it shows a tooltip saying so.

### Changed
- The Position setting is a single row of four corner icons, styled like the Theme switch, with the chosen corner named beside the heading; it replaces the 2×2 grid of labelled buttons.

### Fixed
- Pressing the panel's drag handle while the panel sat in its corner (on first open, or after a position reset) made it jump to the top-left of the page. It now starts the drag from where it is, and a press that does not move it no longer counts as moved.

## [v0.2.4] - 2026-10-14

### Changed
- Build tooling upgraded to Vite 8 and `@vitejs/plugin-vue` 6, and the bundled Vue to 3.5.44, clearing every `npm audit` advisory (none of them reached the published bundle). Bundled libraries keep their `@license` headers.
- Theme and Position options in the settings menu have icons: half-filled circle, sun and moon for auto, light and dark, and a screen with the chosen corner marked for each position.
- After a drag, the pill slides from where it was dropped into its corner instead of jumping there (skipped when the browser asks for reduced motion).

## [v0.2.3] - 2026-10-14

### Added
- Drag the pill across the page to move it: on release it snaps to the nearest corner and saves it as the **Position** setting. A press that moves less than 5px still opens and closes the panel.

## [v0.2.2] - 2026-10-14

### Added
- **Position** setting in the settings menu: put the pill and panel in any corner of the page (bottom-right by default), saved in `localStorage` with the other settings and restored by **Reset to defaults**.

## [v0.2.1] - 2026-10-02

### Added
- `Snip::surface()` and `Snip::measure()` on the facade, plus `Snip::profiler()` for the rest of the profiler API.
- Profiler reports show who the request ran for (`user #<id>` or `guest`, from `snip.guard`), so controllers no longer pass that as context.

### Changed
- `surface()` also marks the controller start unless `markControllerStarted()` already did, so the separate call is no longer needed.

## [v0.2.0] - 2026-10-02

### Added
- **Guest links**: from the panel settings, a user who passes the gate creates a link that, opened in a browser that is not logged in, sets a cookie showing the panel there too. One link at a time, kept in the cache, expiring after `guest_links.ttl` minutes (default 120), replaceable and revocable from the panel; guests cannot manage links. Switch off with `SNIP_GUEST_LINKS=false`.
- Guest links record who created them and when (shown in the panel), and creating, opening and revoking a link is logged with the user and IP, using a fingerprint instead of the token.
- **Profiler** tab: a structured view (phase bar, step waterfall, calls filterable by kind, Blade views, repeated Redis keys, slowest and repeated SQL) plus a raw plain-text view with a copy button, showing where the request spent its time — lifecycle phases, `Profiler::measure()` steps as a tree, and the SQL, Redis, cache, HTTP and Blade view activity inside each step. Switched on with `SNIP_PROFILER=true` (config `profiler.enabled`, off by default).
- Inertia v2 support: the payload is shared as `page.props._snip` and the panel refreshes on every SPA navigation (`inertia:success`), without a full page reload.
- Captures recorded during a redirect or an Inertia XHR are carried to the next page through the session (config `pending_redirects`, `SNIP_PENDING_REDIRECTS`, on by default).

### Changed
- The cache and queue endpoints now use the same access check as the panel (the `snip.guard` user, or a guest link) instead of `Gate::allows('viewSnip')` on the default guard.
- The panel resizes from any edge or corner (previously only the native bottom-right grip, which grew the bottom-right-anchored panel away from the cursor). "Remember panel size" is now on by default, and the size is stored when a resize ends rather than on every viewport change.
- "Remember panel size" row shows a **reset** button once the panel has been resized, putting it back to the default size and forgetting the stored one.
- Settings menu has a **Reset to defaults** button: restores theme and persistence toggles and forgets the stored open state, size and position.
- Internals split into focused collaborators (capturing decision, payload builder, snippet renderer, pending payload store, caller resolver); the public `Snip` API is unchanged.

### Upgrade notes
- Re-publish the panel bundle: `php artisan vendor:publish --tag=snip-assets --force`.
- If you published `config/snip.php`, add the new `profiler` and `guest_links` blocks (see the package config); without them the defaults apply (profiler off, guest links on with a 120-minute lifetime).

## [v0.1.x] - 2026-05-09 … 2026-05-15

Released across tags v0.1.0–v0.1.6.

### Added
- Request-scoped timings API: `Snip::start($label)`, `Snip::timing($label)` and the `snip_time()` global helper. Without a prior `Snip::start` mark, `Snip::timing` measures elapsed time from `LARAVEL_START`.
- Tabbed panel UI: a **Timings** tab next to **Snips**, listing every captured timing with a horizontal bar chart scaled against the slowest entry. Sort toggle between duration-desc and chronological order.
- New config key `limits.max_timings_per_request` (default `500`).
- Request-scoped milestones API: `Snip::milestone($label)` and the `snip_here()` global helper. A non-fatal alternative to `dd()` — records label + caller `file:line` + ms-from-request-start. Repeated calls with the same label are not deduped.
- Third tab in the panel: **Milestones**, with chronological list and per-row Δ delta from the previous milestone.
- New config key `limits.max_milestones_per_request` (default `1000`).

### Changed
- Middleware payload shape changed from a flat array of snip entries to `{ "snips": [...], "timings": [...] }`. The frontend transparently falls back to the v1 shape when consumers run an outdated bundle, but new bundles depend on the v2 shape.
- Bottom-right badge now shows the combined snips + timings count. The panel auto-selects the Timings tab when only timings were captured.

### Removed
- Temporary `Log::debug('[laravel-snip] inject check', ...)` diagnostic that was added during the eshop integration. Production middleware path is silent again.

## [v1]

### Added
- Initial release.
- `snip($value, $label = null)` global helper that captures values to a request-scoped store.
- `Snip` facade (alias for `RudolfBruder\LaravelSnip\SnipManager`).
- `InjectSnip` middleware that injects a Vue-based `<laravel-snip>` web component before `</body>` on HTML responses for users who pass the `viewSnip` gate.
- `SnipDumper` value serializer with depth/array/string limits, redacted-key support, and circular-reference protection.
- Horizon-style `Gate::define('viewSnip', ...)` (default: only `local` env).
- Asset route at `/vendor/snip/snip.js` serving the compiled bundle, also gated.
