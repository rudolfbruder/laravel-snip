# laravel-snip

A `dd()` that does not die. Capture values, timings, milestones, and `window.dataLayer` events during one Laravel request and review them on-page in a Horizon-style admin-only panel — safe to leave in production code, hidden from customers, visible only to authorised users.

## Why

`dd()` and `dump()` are great in development but useless once you ship: they expose data to every visitor, halt the response, and break HTML pages. `laravel-snip` fixes this by:

- Buffering captures into a per-request in-memory store.
- Injecting a self-contained Vue 3 web component (`<laravel-snip>`) into the HTML response — but only for users who pass the `viewSnip` gate.
- Rendering each capture in an isolated Shadow DOM (no CSS conflicts with the host page).
- Persisting nothing — every request starts clean.

Four tabs in the panel:

| Tab | API | Purpose |
| --- | --- | --- |
| Snips | `Snip::add($value, $label)` / `snip(...)` | Inspect any value as a typed tree (depth/array/string limits, redacted keys, circular-ref guard). |
| Timings | `Snip::start($label)` + `Snip::timing($label)` / `snip_time(...)` | Profile blocks of code — origin defaults to request start. |
| Milestones | `Snip::milestone($label)` / `snip_here(...)` | Non-fatal `dd()` alternative — confirm a branch executed. |
| DataLayer | (frontend-only) | Tee'd `window.dataLayer.push` — site-defined GTM events filtered from `gtm.*` internals. |

## Requirements

- PHP 8.1+
- Laravel 10, 11, or 12

## Installation

```bash
composer require rudolfbruder/laravel-snip
php artisan snip:install
```

`snip:install` is the Horizon-style one-shot installer. It:

1. Publishes the gate provider stub to `app/Providers/SnipServiceProvider.php`.
2. Publishes the compiled JS bundle to `public/vendor/snip/snip.js`.

Final manual step — register the published provider:

```php
// Laravel 10 — config/app.php
'providers' => [
    // ...
    App\Providers\SnipServiceProvider::class,
],

// Laravel 11+ — bootstrap/providers.php
return [
    App\Providers\AppServiceProvider::class,
    App\Providers\SnipServiceProvider::class,
];
```

The config file is optional, published only when overriding defaults:

```bash
php artisan vendor:publish --tag=snip-config
```

### Re-publishing after a package update

After every `composer update rudolfbruder/laravel-snip`, re-copy the freshly compiled bundle:

```bash
php artisan snip:publish
```

(Equivalent to `php artisan vendor:publish --tag=snip-assets --force` — the dedicated command is just shorter.)

The injected `<script>` URL automatically gets `?v=<mtime>` appended, so browsers fetch the new bundle on the next request — no hard reload needed.

### Why the asset publish step is mandatory

The bundle is served from your application's `public/` directory by nginx/Herd/Apache directly — there is no Laravel route for it. Without `snip:install` (or a manual `vendor:publish --tag=snip-assets`) the browser fetches a 404 and the panel cannot render. Same pattern Telescope, Horizon, Debugbar, and Nova use.

### Manual publish (advanced)

If you prefer to drive the publishes yourself instead of using `snip:install`:

```bash
php artisan vendor:publish --tag=snip-provider
php artisan vendor:publish --tag=snip-assets
```

## Authorising who can see snips

Open the published `app/Providers/SnipServiceProvider.php` (it extends `RudolfBruder\LaravelSnip\SnipApplicationServiceProvider`) and define the gate — same shape Horizon uses:

```php
namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use RudolfBruder\LaravelSnip\SnipApplicationServiceProvider;

class SnipServiceProvider extends SnipApplicationServiceProvider
{
    protected function gate(): void
    {
        Gate::define('viewSnip', function ($user = null) {
            return in_array($user?->email, [
                'admin@example.com',
            ], true);
        });
    }
}
```

If the provider is never published, the package falls back to a default gate that allows access only when `app()->environment('local')` returns true — useful for development, never enough for production.

The middleware uses the framework's default auth guard. To target a different guard (e.g. an `employee` guard for a CMS), set `snip.guard` or `SNIP_GUARD=employee` in `.env`.

## Usage

### Capturing values

```php
use RudolfBruder\LaravelSnip\Facades\Snip;

Snip::add(['user_id' => $user->id, 'cart' => $cart->toArray()], 'checkout-context');
```

The `snip()` global helper is equivalent and returns the value unchanged so it can be inlined inside expressions:

```php
return snip(User::findOrFail($id), 'lookup')->only(['name', 'email']);
```

### Capturing timings

```php
// No start() — measures from request start (LARAVEL_START)
Snip::timing('after-cart-load');

// With start() — measures only the wrapped block
Snip::start('elastic-fetch');
$result = app(ColoredProductListElasticRepository::class)->fetchColoredProduct($id);
Snip::timing('elastic-fetch');
```

`Snip::timing($label)` records elapsed milliseconds since either the matching `Snip::start($label)` mark (when one exists for that label) or `LARAVEL_START`. Each call appends a row, even with the same label.

The `snip_time($label)` global helper is equivalent.

### Capturing milestones

Non-fatal alternative to `dd()` for confirming a code path executed:

```php
Snip::milestone('reached-checkout-step-2');

if ($eligible) {
    snip_here('inside-eligible-branch');
    // ...
}
```

Each call appends one row to the Milestones tab with label, caller `file:line`, and ms since request start. Repeated calls with the same label are not deduped.

### DataLayer events (GTM)

The DataLayer tab patches `window.dataLayer.push` on panel mount and shows every push as an expandable row. By default only **site-defined events** show (entries with a string `event` key not starting with `gtm.`). Use the in-tab "Show all" toggle to inspect GTM internals (`gtm.js`, `gtm.dom`, `gtm.click`, etc.).

This tab requires no backend wiring — it's pure frontend instrumentation that runs once the panel is visible to an authorised user.

## Panel UI

- Bottom-right pill shows total capture count. Click or press **Cmd+K / Ctrl+K** to toggle.
- Tab strip in the header: Snips, Timings, Milestones, DataLayer. Active tab is persisted to `localStorage`.
- **Theme button** (☀ / ☾ / ◐) in the header cycles light → dark → auto. Auto follows `prefers-color-scheme`. Persisted to `localStorage`.
- Each Snips entry header has a **copy** button — copies the entry's JSON to the clipboard.
- Eloquent models render attributes plus already-loaded relations (no lazy-load triggered). Memory size of every snip is shown next to its `time_ms` chip.

## Configuration

Published file: `config/snip.php`.

| Key | Default | Purpose |
| --- | --- | --- |
| `enabled` | `env('SNIP_ENABLED', true)` | Master kill-switch. `SNIP_ENABLED=false` disables everywhere. |
| `asset_path` | `/vendor/snip/snip.js` | Public-relative path where the published bundle lives. |
| `guard` | `env('SNIP_GUARD')` (default null) | Auth guard the gate authorises against. Null = framework default. |
| `show_memory` | `env('SNIP_SHOW_MEMORY', true)` | Capture approximate serialized size per snip. |
| `datalayer` | `env('SNIP_DATALAYER', true)` | Enable the DataLayer tab + `window.dataLayer.push` patching. Set false to hide the tab. |
| `limits.max_depth` | `6` | Recursion depth when serializing values. |
| `limits.max_array_items` | `200` | Truncate arrays/collections after this many items. |
| `limits.max_string_length` | `5000` | Truncate strings longer than this many chars. |
| `limits.max_entries_per_request` | `200` | Hard cap on `Snip::add(...)` calls per request. |
| `limits.max_timings_per_request` | `500` | Hard cap on `Snip::timing(...)` calls per request. |
| `limits.max_milestones_per_request` | `1000` | Hard cap on `Snip::milestone(...)` calls per request. |
| `redact_keys` | `['password', 'token', 'secret', 'authorization', 'api_key', ...]` | Case-insensitive keys whose value is replaced with `***REDACTED***`. |

## How it works

```
Code calls Snip::add / start / timing / milestone
                  ↓
         SnipManager (request-scoped singleton)
                  ↓
         InjectSnip middleware on response
                  ├── enabled / has-captures / text-html / </body> / gate? — all yes ─→ inject
                  └── any no ─→ response unchanged
                  ↓
<laravel-snip data-payload="…">…</laravel-snip>
<script src="/vendor/snip/snip.js?v=<mtime>" defer></script>
                  ↓
Web server serves the static bundle (no PHP route)
                  ↓
Vue web component mounts in Shadow DOM, parses payload, opens panel.
                  ↓
Inside the panel, window.dataLayer.push is teed for the DataLayer tab.
```

Per-request only — no DB, no cache, no logs.

## Building the JS bundle (package development only)

```bash
cd packages/rudolfbruder/laravel-snip
npm install
npm run build           # one-off build → dist/snip.js
npm run dev             # watch mode while iterating on resources/js/
```

After each rebuild, re-run `php artisan snip:publish` in your consumer app to copy the freshly built bundle into `public/vendor/snip/`.

## Testing

```bash
composer install
vendor/bin/pest --compact
```

## Security

The package is designed so captured data only ever reaches the gated user who triggered the request. Specifically:

- The middleware never injects unless the `viewSnip` gate allows the resolved user.
- The bundle file itself contains no captured data — only the renderer. Static-served via the consumer's `public/` directory.
- The DataLayer tab patches `window.dataLayer.push` only after the panel has mounted (which only happens for gated users). Regular visitors never see the wrapper.
- The middleware only injects into `text/html` responses with a `</body>` tag — JSON, redirects, streamed responses, file downloads are left alone.
- Common credential fields (`password`, `token`, `api_key`, `secret`, `authorization`, ...) are redacted automatically; extend `redact_keys` for project-specific sensitive fields.
- **Cache hardening**: every response that gets an injection is forcibly marked `Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0` plus `Pragma: no-cache` plus `Vary: Cookie`. This stops a shared cache (Varnish, Cloudflare, Laravel response cache) from serving one user's captures to another.
- **Octane / queue safety**: `SnipManager` and `SnipDumper` are bound `scoped()` rather than `singleton()`, so per-request state is reset by Laravel's container even in long-lived workers.
- **JSON encoding failures** are reported via `report()` and the panel is silently dropped — no half-broken HTML, no leak.

### Limits / consumer responsibilities

- The default fallback gate allows access in `app()->environment('local')` regardless of authentication. If your local domain is reachable from the internet (e.g. via a tunnel), publish the provider stub and define a stricter gate.
- The dumper renders Eloquent models with their attributes plus already-loaded relations. If sensitive fields are not covered by `redact_keys`, they will be visible to gated users — extend the list for any project-specific PII (SSN, IBAN, address, …).
- Caller `file:line` for every capture is included in the payload, exposing some server-side path structure to gated users. Acceptable trade-off for click-to-source navigation, but worth knowing.

## License

MIT.
