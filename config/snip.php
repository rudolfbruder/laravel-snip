<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | When false, snip() is a no-op and the middleware never injects anything.
    | Use this as a quick kill-switch in production via SNIP_ENABLED=false.
    |
    */

    'enabled' => env('SNIP_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Display mode
    |--------------------------------------------------------------------------
    |
    | Controls when the panel is injected into rendered HTML responses.
    |
    |   - 'on_capture' (default): panel appears only on requests where the
    |     application code called `Snip::add` / `Snip::timing` / `Snip::milestone`
    |     at least once. Quiet pages stay clean.
    |   - 'always': panel appears on every gated HTML response, even when no
    |     captures were recorded. Cache / queue tabs are still browsable on
    |     pages where no code instrumented anything. Heavier — runs the cache
    |     snapshot on every request.
    |
    */

    'display_mode' => env('SNIP_DISPLAY_MODE', 'on_capture'),

    /*
    |--------------------------------------------------------------------------
    | Asset path
    |--------------------------------------------------------------------------
    |
    | Public-relative path where the compiled web component bundle lives
    | after `php artisan vendor:publish --tag=snip-assets`. The path is
    | served directly by the web server from your application's public/
    | directory; no Laravel route handles it.
    |
    */

    'asset_path' => '/vendor/snip/snip.js',

    /*
    |--------------------------------------------------------------------------
    | Auth guard
    |--------------------------------------------------------------------------
    |
    | Name of the auth guard the panel should authenticate against. Leave
    | null to use the application's default guard. Set to a specific guard
    | (e.g. 'employee', 'admin') if your CMS uses a non-default guard for
    | the user that should see snip output.
    |
    */

    'guard' => env('SNIP_GUARD'),

    /*
    |--------------------------------------------------------------------------
    | Show memory footprint per snip
    |--------------------------------------------------------------------------
    |
    | Approximate memory size (via `strlen(serialize($value))`) is captured
    | for every snip entry and rendered next to the elapsed-time chip in
    | the panel sidebar. Set to false (or `SNIP_SHOW_MEMORY=false`) to skip
    | the serialization step if profiling shows it too costly.
    |
    */

    'show_memory' => env('SNIP_SHOW_MEMORY', true),

    /*
    |--------------------------------------------------------------------------
    | DataLayer tab
    |--------------------------------------------------------------------------
    |
    | When true, the panel patches `window.dataLayer.push` and shows a fourth
    | "DataLayer" tab listing every site-defined GTM event captured during
    | the page's lifetime. Set to false (or `SNIP_DATALAYER=false`) to skip
    | the patching and hide the tab entirely.
    |
    */

    'datalayer' => env('SNIP_DATALAYER', true),

    /*
    |--------------------------------------------------------------------------
    | Cache tab
    |--------------------------------------------------------------------------
    |
    | When enabled, the panel adds a "Cache" tab that introspects the
    | application's active cache store and lists the keys it currently holds.
    | Behaviour per driver:
    |
    |   - redis      → SCAN with the configured prefix (capped at max_keys).
    |   - array      → in-memory storage keys.
    |   - database   → SELECT key FROM <cache_table> LIMIT max_keys.
    |   - file       → directory walk; keys are SHA1-hashed on disk so the
    |                  original key cannot be recovered (hashes shown only).
    |   - memcached  → not enumerable; tab shows driver name only.
    |
    | Enumerating large caches can be expensive — `max_keys` caps the result
    | so the JSON payload stays small. The collector never returns cached
    | values, only key metadata (key/hash, ttl when available, byte size).
    |
    */

    'cache' => [
        'enabled' => env('SNIP_CACHE', true),
        'max_keys' => 500,

        /*
        | When true, the redis driver scans only keys that start with the
        | configured cache prefix — i.e. keys Laravel itself wrote via
        | Cache::put(). Set to false (or `SNIP_CACHE_MATCH_PREFIX=false`) to
        | scan the entire redis database, including session keys, queue keys,
        | broadcast channels, and any other keys living in the same instance.
        | Has no effect on other drivers; they already enumerate everything.
        */
        'match_prefix' => env('SNIP_CACHE_MATCH_PREFIX', true),

        /*
        | Explicit prefix override. By default the collector reads the prefix
        | from `Illuminate\Cache\RedisStore::getPrefix()` (which on modern
        | Laravel is `${APP_NAME}_cache_`). Set this to a custom value (for
        | example `laravel_` without the `_cache_` suffix) when the runtime
        | prefix differs from what `getPrefix()` reports — e.g. when the
        | Redis connection's `options.prefix` is what shows up on redis-cli
        | and the cache store's own prefix has been emptied. The value is
        | used for both the SCAN pattern and stripping the prefix off keys
        | rendered in the panel.
        */
        'prefix' => env('SNIP_CACHE_PREFIX'),

        /*
        | URL prefix for the cache value-lookup endpoint. The panel sends a
        | GET request to `<route_prefix>/cache?key=…` when a user clicks a
        | key to inspect its contents. The route is gated by the `viewSnip`
        | gate just like the panel HTML injection, so an unauthorised visitor
        | reaches a 403 instead of the value. Change this if your app already
        | uses `/_snip/*` for something else.
        */
        'route_prefix' => env('SNIP_ROUTE_PREFIX', '_snip'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue tab
    |--------------------------------------------------------------------------
    |
    | When enabled, the panel adds a "Queue" tab that lists jobs from the
    | configured queue driver. The view has four sub-states:
    |
    |   - failed     → from the `queue.failer` provider (any driver).
    |   - pending    → jobs available to run right now.
    |   - scheduled  → jobs delayed to a future time.
    |   - completed  → Laravel Horizon only (reads horizon:completed_jobs).
    |
    | Pending/scheduled reads only support `database` and `redis` queue
    | drivers (sqs/beanstalkd/sync are not enumerable). All data is fetched
    | lazily from the `route_prefix`/queue endpoint when the user opens the
    | tab, so request payloads stay small. Each list is capped by `max_scan`
    | and paginated server-side using `per_page`.
    |
    */

    'queue' => [
        'enabled' => env('SNIP_QUEUE', true),

        /*
        | Per-page page size for the queue endpoint. The full match-set is
        | capped first by `max_scan`, then sliced to `per_page` items.
        */
        'per_page' => 50,

        /*
        | Hard cap on the number of rows the snapshot inspects before
        | paginating. Keep this comfortably above `per_page` so the user can
        | page through a few screens, but low enough that a giant queue
        | doesn't stall the request.
        */
        'max_scan' => 2000,

        /*
        | Redis queue names to scan for pending/scheduled jobs. Laravel
        | writes each queue to `queues:<name>` (list) and
        | `queues:<name>:delayed` (zset); we can't reliably enumerate every
        | queue name across phpredis/predis without scanning the whole DB,
        | so the names are configured explicitly. Leave empty to fall back
        | to the connection's default queue name plus the literal
        | "default". Ignored for the database driver — it lists every row.
        */
        'queues' => array_filter(array_map('trim', explode(',', (string) env('SNIP_QUEUE_NAMES', '')))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dumper limits
    |--------------------------------------------------------------------------
    |
    | Hard caps applied while serializing captured values into the JSON tree
    | embedded into the HTML response. These prevent enormous payloads,
    | infinite recursion through circular references, and exposure of multi-MB
    | model collections.
    |
    */

    'limits' => [
        'max_depth' => 6,
        'max_array_items' => 200,
        'max_string_length' => 5000,
        'max_entries_per_request' => 200,
        'max_timings_per_request' => 500,
        'max_milestones_per_request' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redacted keys
    |--------------------------------------------------------------------------
    |
    | Case-insensitive list of array keys / object property names whose value
    | should be replaced with "***REDACTED***" in the rendered tree. The
    | default list covers the most common credential fields.
    |
    */

    'redact_keys' => [
        'password',
        'password_confirmation',
        'token',
        'api_token',
        'access_token',
        'refresh_token',
        'remember_token',
        'secret',
        'api_key',
        'authorization',
    ],

];
