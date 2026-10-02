# Changelog

All notable changes to `laravel-snip` will be documented in this file.

## [Unreleased]

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
