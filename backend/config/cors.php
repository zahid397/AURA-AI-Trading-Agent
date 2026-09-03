<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | The Vue 3 SPA (frontend/) talks to this API from its Vite dev origin
    | and needs cookies (Sanctum SPA auth) sent cross-origin, which requires
    | an explicit origin allowlist and supports_credentials = true — a
    | wildcard origin ("*") cannot be combined with credentials per the
    | Fetch spec, so the allowed origins are listed explicitly.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(array_map(
        'trim',
        explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173'))
    )),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['X-Request-Id'],

    'max_age' => 0,

    'supports_credentials' => true,

];
