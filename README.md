# laravel-snip

A `dd()` that does not die. Capture values during a Laravel request with a global `snip()` helper and review them on-page in a Horizon-style admin-only panel — safe to leave in production code, hidden from customers, visible only to authorized users.

## Why

`dd()` and `dump()` are great in development but useless once you ship: they expose data to every visitor, halt the response, and break HTML pages. `laravel-snip` fixes this by:

- Buffering captures into a per-request in-memory store.
- Injecting a self-contained Vue 3 web component (`<laravel-snip>`) into the HTML response — but **only** for users who pass the `viewSnip` gate.
- Rendering each captured value as a collapsible tree, just like `dd()`, in an isolated Shadow DOM (no CSS conflicts with the host page).
- Persisting nothing — every request starts clean.

## Requirements

- PHP 8.1+
- Laravel 10, 11, or 12

## Installation

```bash
composer require rudolfbruder/laravel-snip
```

The service provider auto-registers via Laravel package discovery.

Optionally publish the config:

```bash
php artisan vendor:publish --tag=snip-config
```

## Authorize who can see snips

Inside `App\Providers\AppServiceProvider::boot()` (or any service provider), define the `viewSnip` gate exactly the same way you would for Horizon:

```php
use Illuminate\Support\Facades\Gate;

public function boot(): void
{
    Gate::define('viewSnip', function ($user = null) {
        return $user?->is_admin === true;
    });
}
```

If you do not override the gate, the default allows access only when `app()->environment('local')` returns true.

## Usage

Wrap any value:

```php
snip(['user_id' => $user->id, 'cart' => $cart->toArray()], 'checkout-context');
```

`snip()` returns the value unchanged, so you can inline it inside expressions:

```php
return snip(User::findOrFail($id), 'lookup')->only(['name', 'email']);
```

Or use the facade:

```php
use RudolfBruder\LaravelSnip\Facades\Snip;

Snip::add($order, 'order');
```

Reload the page as an authorized user — a small "snip" badge appears at the bottom-right corner with the entry count. Click to expand the panel.

## Configuration

Published file: `config/snip.php`.

| Key                                        | Default                       | Purpose                                                                |
| ------------------------------------------ | ----------------------------- | ---------------------------------------------------------------------- |
| `enabled`                                  | `env('SNIP_ENABLED', true)`   | Master kill-switch. Set `SNIP_ENABLED=false` to disable everywhere.    |
| `asset_route`                              | `/vendor/snip/snip.js`        | Path under which the compiled web component bundle is served.          |
| `limits.max_depth`                         | `6`                           | Maximum recursion depth when serializing values.                       |
| `limits.max_array_items`                   | `200`                         | Truncate arrays/collections after this many items.                     |
| `limits.max_string_length`                 | `5000`                        | Truncate string previews longer than this many chars.                  |
| `limits.max_entries_per_request`           | `200`                         | Hard cap on `snip()` calls captured per request.                       |
| `redact_keys`                              | `['password', 'token', ...]`  | Case-insensitive keys whose value is replaced with `***REDACTED***`.   |

## How it works

```
Controller → snip($value, 'label') → SnipManager (request-scoped) → InjectSnip middleware
                                                                       ↓
                                                            Gate::allows('viewSnip')?
                                                                       ↓
                                          <laravel-snip data-payload="…json…"></laravel-snip>
                                          <script src="/vendor/snip/snip.js"></script>
                                                                       ↓
                                          Vue web component mounts in Shadow DOM
```

Nothing is stored on disk or in cache — payloads are scoped to the current request only.

## Building the JS bundle

The package ships with `dist/snip.js` placeholder. To produce the real Vue-based bundle (~35 KB gzipped), run inside the package directory:

```bash
npm install
npm run build
```

For local development with hot rebuilds:

```bash
npm run dev
```

## Testing

```bash
composer install
vendor/bin/pest
```

## Security

- The middleware never runs the injection unless the `viewSnip` gate allows the current user.
- The asset route returns `404` (not `403`) when the gate denies, to avoid leaking the existence of the package.
- Common credential fields (`password`, `token`, `api_key`, `secret`, `authorization`, ...) are redacted automatically; extend `redact_keys` for project-specific sensitive fields.
- The middleware only injects into `text/html` responses that contain a `</body>` tag — JSON, redirects, and streamed responses are left alone.

## License

MIT.
