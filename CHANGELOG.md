# Changelog

All notable changes to `laravel-snip` will be documented in this file.

## [Unreleased]

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
