<?php

use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;

return [
    /*
    |--------------------------------------------------------------------------
    | API Path
    |--------------------------------------------------------------------------
    |
    | The path where Scramble will look for API routes to document.
    |
    */
    'api_path' => 'api',

    /*
    |--------------------------------------------------------------------------
    | API Domain
    |--------------------------------------------------------------------------
    */
    'api_domain' => null,

    /*
    |--------------------------------------------------------------------------
    | Export Path
    |--------------------------------------------------------------------------
    */
    'export_path' => 'api.json',

    /*
    |--------------------------------------------------------------------------
    | OpenAPI Info
    |--------------------------------------------------------------------------
    */
    'info' => [
        'version' => env('API_VERSION', '1.0.0'),
        'description' => 'AlphaPanel Docker hosting control panel REST API. Sanctum Bearer authentication with ability-scoped tokens and optional per-token IP whitelisting.',
    ],

    /*
    |--------------------------------------------------------------------------
    | UI Settings
    |--------------------------------------------------------------------------
    |
    | path: URL where the Swagger UI documentation page is served.
    | User-specified: /api/docs
    |
    */
    'ui' => [
        'title' => 'AlphaPanel API',
        'theme' => 'dark',
        'hide_try_it' => false,
        'hide_schemas' => false,
        'logo' => '',
        'try_it_credentials_policy' => 'include',
        'layout' => 'responsive',
    ],

    /*
    |--------------------------------------------------------------------------
    | Servers
    |--------------------------------------------------------------------------
    */
    'servers' => null,

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    |
    | Lock down docs to authenticated panel users only in production.
    |
    */
    'middleware' => [
        'web',
        RestrictedDocsAccess::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Extensions
    |--------------------------------------------------------------------------
    */
    'extensions' => [],
];
