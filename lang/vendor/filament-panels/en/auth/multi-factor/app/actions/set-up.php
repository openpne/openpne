<?php

// See the ja override: replaces Filament's default (which names one product with a US-fixed link)
// with a neutral, durable instruction that points at the device's own app-store search.
return [
    'modal' => [
        'description' => 'You need an authenticator app that generates a one-time code at login. Search your device\'s app store for "authenticator" and install one.',
    ],
];
