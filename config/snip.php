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
