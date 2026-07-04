<?php

// See the ja override: replaces Filament's default description (which names Google Authenticator
// with a US-fixed App Store link) with a product-neutral one pointing at the device's own store.
return [
    'modal' => [
        'description' => 'You need a TOTP authenticator app (such as Google Authenticator, Microsoft Authenticator, or 1Password). Install one from your device\'s app store.',
    ],
];
