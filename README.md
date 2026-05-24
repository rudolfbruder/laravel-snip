<p align="center">
  <img src="art/banner.svg" alt="laravel-snip" width="640">
</p>

# laravel-snip

A `dd()` that does not die.

Drop a `snip($order)` anywhere in your Laravel app and the value shows up in an admin-only on-page panel — no log files to grep, no debugger to attach, no broken page. Works the same on full HTML responses, Livewire components, and Inertia SPA navigations.

```php
snip($order, 'before-payment');
```

Open the page as an authorised user, hit **Cmd+K** (or **Ctrl+K**), and you have a Horizon-style panel pinned to the corner of the page with everything you captured during the request — values, timings, milestones, dataLayer events, cache keys, and queue state.

Safe to leave in production. Gated, redacted, never cached.

---

## Why not just `dd()` or Debugbar?

**vs. `dd()` / `dump()`**

| | `dd()` | `dump()` | `snip()` |
| --- | --- | --- | --- |
| Breaks the page | Yes — terminates the response | Sometimes — prints into the HTML | No — value rendered in a side panel |
| Survives a redirect | No — the response is killed before the redirect runs | No — output lost on the next request | Yes — captures are flashed across the redirect and shown on the landing page |
| Works in API / JSON / Inertia XHR responses | No — corrupts the response body | No — same | Yes — shipped via Inertia shared prop, or carried across redirects until a panel-renderable response |
| Multiple values per request | One, then dead | Yes, inline | Yes — and grouped by label, with timings and milestones in their own tabs |
| Inspect later | No — gone after the page | No — gone after the page | Yes — pin the panel open, navigate freely, keep capturing |
| Safe to leave in prod | Never | Never | Yes — gated, redacted, no-store cached |

**vs. Laravel Debugbar / Telescope / Clockwork**

| | Debugbar / Telescope / Clockwork | `snip()` |
| --- | --- | --- |
| Production-safe | Discouraged or off-limits — they capture for *every* request and store a lot | Yes — recording short-circuits for non-gated users before any backtrace, dump, or serialise runs |
| Per-user gating | Coarse (env / IP / config flag) | Fine-grained Laravel `Gate::define('viewSnip', …)` — pick any user predicate |
| Performance hit on the hot path | Always-on instrumentation | Zero cost for non-gated users; ~one gate check per request for gated users |
| What gets recorded | The whole request (queries, jobs, mail, views, etc.) — opinionated | Whatever *you* write `snip(…)` against — surgical, intent-driven |
| Storage | Database / files / external services to clean up | Session flash + in-memory; nothing to migrate, nothing to expire |
| Setup | Migrations, providers, dashboards, retention | `composer require` + one gate closure |

The trade is deliberate. Debugbar / Telescope answer "what happened in this request?". `snip()` answers "what is this value right now?" — the same question `dd()` answers, with none of the costs of `dd()`.

What it carries over from a production-safe tool like Horizon: scoped per-request state, an admin-only gate, private cache headers on every injected response, and zero impact on users who do not match the gate.

---

## Installation

```bash
composer require rudolfbruder/laravel-snip
php artisan snip:install
```

The installer publishes `config/snip.php`, copies the frontend bundle to `public/vendor/snip/`, and creates `app/Providers/SnipServiceProvider.php` where you define who can see the panel.

Register the provider:

```php
// Laravel 10 — config/app.php
App\Providers\SnipServiceProvider::class,

// Laravel 11+ — bootstrap/providers.php
App\Providers\SnipServiceProvider::class,
```

After every `composer update rudolfbruder/laravel-snip`, refresh the bundle:

```bash
php artisan snip:publish
```

## Who can see the panel

Edit `app/Providers/SnipServiceProvider.php`:

```php
protected function gate(): void
{
    Gate::define('viewSnip', function ($user = null) {
        return $user?->is_admin === true;
    });
}
```

Without an override, only `app()->environment('local')` sees the panel. Unauthorised users never receive captured data — the middleware never injects, the Inertia share callback returns `null`, and the recording API short-circuits before any backtrace or serialisation runs.

---

## Laravel support

Three top-level helpers cover most use:

```php
snip($value, 'label');     // capture a value
snip_time('elastic');      // record an elapsed-time entry
snip_here('reached-step-2'); // drop a milestone breadcrumb
```

Or use the facade:

```php
use RudolfBruder\LaravelSnip\Facades\Snip;

Snip::add($order, 'order');
Snip::start('payment');
$result = $gateway->charge($order);
Snip::timing('payment');
Snip::milestone('payment-confirmed');
```

Reload the page. The panel pill appears bottom-right; click it or press **Cmd+K / Ctrl+K**.

That is the entire surface area. No bootstrapping, no middleware to register, no `<script>` tags to add — the package wires everything into the framework on install.

## Inertia support

Works out of the box. The package detects Inertia and registers a shared `_snip` prop so every visit — initial HTML and subsequent SPA XHR navigations — carries the current request's captures.

```php
// Inertia controller
public function show(Order $order)
{
    snip($order, 'order');
    snip_time('elastic-query');

    return Inertia::render('Orders/Show', [...]);
}
```

Captures appear in the panel without a full page reload. When the panel is already open and you navigate to another page via `<Link>`, the panel re-renders with the new request's data.

For Inertia POST → 303 → GET XHR flows (form submissions, destroy actions), captures recorded in the POST handler are flashed to the session and replayed on the next request, so nothing gets lost across the redirect.

---

## Tabs

The panel has six tabs. Each tab is independent — you only see the ones with data, or all of them when `display_mode = always`.

### Snips

Inspect any PHP value as a typed, collapsible tree.

```php
snip($order, 'order');
snip(['user' => $user, 'cart' => $cart], 'checkout-state');
snip(Order::with('items')->find($id));
```

Returns the value unchanged so it can be inlined inside expressions:

```php
return snip(User::find($id), 'lookup')->email;
```

Eloquent models, collections, arrays, objects, and primitives all render with type badges, child counts, and a memory size estimate. Keys matching `password`, `token`, `secret`, etc. are auto-redacted.

### Timings

Profile blocks of code with start/stop marks.

```php
Snip::start('elasticsearch');
$products = $repo->search($query);
Snip::timing('elasticsearch');

snip_time('build-payload');         // measured from request start
```

Each timing shows the elapsed duration in milliseconds plus where in the request lifecycle it ran, so you can see at a glance which block ate the budget.

### Milestones

Confirm a code path executed without breaking the page.

```php
snip_here('discount-applied');
snip_here('admin-fallback-path');
```

A non-fatal alternative to `dd()`. Useful for confirming a feature flag fired, a guard clause passed, or a fallback branch took over — anywhere you would have reached for `dump()` and a page reload.

### DataLayer

Tees every `window.dataLayer.push(...)` call into a panel tab so GTM events are visible without opening the GTM debugger.

```js
window.dataLayer.push({
    event: 'add_to_cart',
    ecommerce: { items: [...] },
});
```

The tab shows the event name, the full payload, and the order in which events were pushed during the page lifetime. Site-defined events are highlighted; framework noise (`gtm.js`, `gtm.dom`, `gtm.load`) is collapsed.

Disable with `SNIP_DATALAYER=false`.

### Cache

Browse the active cache store without a separate tool.

Supported drivers:

| Driver | Lists keys | Inspect value |
| --- | --- | --- |
| redis | Yes (SCAN with prefix) | Yes (handles strings, lists, sets, hashes, zsets) |
| array | Yes | Yes |
| database | Yes | Yes |
| file | Yes (hashed keys only) | No |
| memcached | No (driver not enumerable) | — |

Click any key to fetch its value on demand — the panel never embeds cache contents in the HTML response. Useful for confirming a `Cache::remember(...)` actually wrote, or seeing what is currently under `user:123:cart`.

Disable with `SNIP_CACHE=false`.

### Queue

Inspect jobs across the configured queue driver — failed, pending, scheduled, and (Horizon only) completed.

| State | Source |
| --- | --- |
| failed | `queue.failer` — works on any driver |
| pending | `redis` / `database` queues — jobs ready to run |
| scheduled | `redis` / `database` queues — jobs delayed to a future time |
| completed | Horizon `completed_jobs` table |

Useful for verifying a job dispatch actually landed, checking why a notification has not gone out yet, or watching a queue drain during a deploy. Each list is paginated and capped (`max_scan` / `per_page`) so a 100k-job queue does not stall the request.

Disable with `SNIP_QUEUE=false`. Name your redis queues with `SNIP_QUEUE_NAMES=high,default,low`.

---

## Configuration

Publish only when overriding defaults:

```bash
php artisan vendor:publish --tag=snip-config
```

Most-used knobs:

| Key | Default | Purpose |
| --- | --- | --- |
| `enabled` | `env('SNIP_ENABLED', true)` | Master kill-switch. |
| `display_mode` | `'on_capture'` | `'always'` to show the pill on every gated response. |
| `guard` | `env('SNIP_GUARD')` | Auth guard for the gate. Null = framework default. |
| `pending_redirects` | `true` | Flash captures across redirect chains. |
| `redact_keys` | `[password, token, secret, …]` | Keys replaced with `***REDACTED***`. |
| `cache.enabled` / `queue.enabled` / `datalayer` | `true` | Toggle individual tabs. |
| `limits.*` | various | Depth / array / string / per-kind hard caps. |

See `config/snip.php` for the full reference with inline notes.

## Security

- Captures only reach users who pass the `viewSnip` gate.
- Common credential keys auto-redacted; extend `redact_keys` for project-specific PII.
- Injected responses are forced `Cache-Control: private, no-store` plus `Vary: Cookie` so shared caches (Varnish, CDN, response cache) cannot leak captures to other users.
- Bundle file contains the renderer only — no captured data lives in JS.
- The recording manager is scoped per request, so Octane and queue workers reset between requests.
- The cache and queue lookup endpoints are gated by the same `viewSnip` ability — a guest hits `403`, not the data.

## License

MIT.
