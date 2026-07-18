<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Stateless bearer-token API (Sanctum) — there is no cookie/session or CSRF
    | layer between the React SPA and this API, so 'supports_credentials' stays
    | false. Only the versioned API surface is exposed to cross-origin requests.
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    // TODO(phase-11): restrict to the real frontend origin(s) before production.
    // Dev uses the Vite dev server at http://localhost:5173; '*' is a temporary
    // convenience for local development only and MUST NOT ship to production.
    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
