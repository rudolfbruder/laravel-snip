# Changelog

All notable changes to `laravel-snip` will be documented in this file.

## [Unreleased]

### Added
- Initial release.
- `snip($value, $label = null)` global helper that captures values to a request-scoped store.
- `Snip` facade (alias for `RudolfBruder\LaravelSnip\SnipManager`).
- `InjectSnip` middleware that injects a Vue-based `<laravel-snip>` web component before `</body>` on HTML responses for users who pass the `viewSnip` gate.
- `SnipDumper` value serializer with depth/array/string limits, redacted-key support, and circular-reference protection.
- Horizon-style `Gate::define('viewSnip', ...)` (default: only `local` env).
- Asset route at `/vendor/snip/snip.js` serving the compiled bundle, also gated.
