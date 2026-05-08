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
    | Asset route
    |--------------------------------------------------------------------------
    |
    | Path under which the compiled web component bundle is served.
    | The route is registered automatically by the service provider and
    | gated behind the same Gate::define('viewSnip', ...) check.
    |
    */

    'asset_route' => '/vendor/snip/snip.js',

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
