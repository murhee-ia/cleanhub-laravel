<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Frontend URL
    |--------------------------------------------------------------------------
    |
    | Base URL of the React SPA. Used to build the links emailed for password
    | reset and to redirect to after email verification. Set FRONTEND_URL in
    | the environment for staging/production; the default targets the local
    | Vite dev server.
    |
    */

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),

    /*
    |--------------------------------------------------------------------------
    | Admin Account
    |--------------------------------------------------------------------------
    |
    | Credentials for the single admin account provisioned by AdminUserSeeder.
    | Exactly one admin ever exists and is never self-registered. Override
    | ADMIN_* in the environment before seeding a real deployment.
    |
    */

    'admin' => [
        'name' => env('ADMIN_NAME', 'CleanHub Admin'),
        'email' => env('ADMIN_EMAIL', 'admin@cleanhub.test'),
        'password' => env('ADMIN_PASSWORD', 'password'),
    ],

];
