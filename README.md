# laravel-snip

A `dd()` that does not die. Capture values, timings, milestones, and `window.dataLayer` events during a Laravel request. Render them on-page in a Horizon-style admin-only panel. Safe to leave in production.

## Installation

```bash
composer require rudolfbruder/laravel-snip
php artisan snip:install
```

Then register the published provider:

```php
// Laravel 10 — config/app.php "providers"
App\Providers\SnipServiceProvider::class,

// Laravel 11+ — bootstrap/providers.php
App\Providers\SnipServiceProvider::class,
```

After every `composer update rudolfbruder/laravel-snip`, run `php artisan snip:publish` to refresh the bundle.

## Authorize who can see snips

Edit `app/Providers/SnipServiceProvider.php`:

```php
protected function gate(): void
{
    Gate::define('viewSnip', function ($user = null) {
        return in_array($user?->email, [
            'admin@example.com',
        ], true);
    });
}
```

Default fallback (when the provider is not published) allows access only in `app()->environment('local')`.

## Usage

| Tab | API | Purpose |
| --- | --- | --- |
| Snips | `Snip::add($value, $label)` / `snip(...)` | Inspect any value as a typed tree. |
| Timings | `Snip::start($label)` + `Snip::timing($label)` / `snip_time(...)` | Profile blocks of code. |
| Milestones | `Snip::milestone($label)` / `snip_here(...)` | Confirm a code path executed. |
| DataLayer | (frontend) | Tee'd `window.dataLayer.push` for GTM debugging. |

```php
use RudolfBruder\LaravelSnip\Facades\Snip;

Snip::add($order, 'order');

Snip::start('elastic');
$result = $repo->fetch();
Snip::timing('elastic');

Snip::milestone('reached-checkout-step-2');
```

Reload the page as an authorised user. A pill appears bottom-right; click or press **Cmd+K / Ctrl+K** to toggle the panel.

## Configuration

Publish only when overriding defaults:

```bash
php artisan vendor:publish --tag=snip-config
```

| Key | Default | Purpose |
| --- | --- | --- |
| `enabled` | `env('SNIP_ENABLED', true)` | Master kill-switch. |
| `guard` | `env('SNIP_GUARD')` | Auth guard for the gate. Null = framework default. |
| `show_memory` | `env('SNIP_SHOW_MEMORY', true)` | Capture serialized size per snip. |
| `datalayer` | `env('SNIP_DATALAYER', true)` | Enable the DataLayer tab. |
| `redact_keys` | `[password, token, ...]` | Keys whose value is replaced with `***REDACTED***`. |
| `limits.*` | various | Depth / array / string / per-kind hard caps. |

## Security

- Captures only ever reach users who pass `viewSnip`.
- Common credential keys auto-redacted; extend `redact_keys` for project-specific PII.
- Injected responses are forced `Cache-Control: private, no-store` plus `Vary: Cookie` so shared caches can't leak captures to guests.
- Bundle file contains the renderer only — no captured data.
- `SnipManager` is `scoped()`, so Octane / queue workers reset per request.

## Contributing

See [INTERNALS.md](INTERNALS.md) for a walkthrough of how the package is wired together.

## License

MIT.
