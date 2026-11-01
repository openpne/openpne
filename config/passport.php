<?php

return [

    // The guard behind the consent screen; the token endpoint and the resource guard hold no session.
    'guard' => 'member',

    'middleware' => [],

    // Either both env values or both key files under storage/ (`php artisan passport:keys`); with
    // neither, OAuth stays closed (docs/internals/mcp.md, "Signing in from a client").
    'private_key' => env('PASSPORT_PRIVATE_KEY'),

    'public_key' => env('PASSPORT_PUBLIC_KEY'),

    'connection' => env('PASSPORT_CONNECTION'),

];
