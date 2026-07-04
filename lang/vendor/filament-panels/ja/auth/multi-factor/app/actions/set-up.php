<?php

// Overrides only the set-up modal description (merged over the package strings by Laravel's
// recursive namespace override). Filament's default names Google Authenticator with a US-fixed
// App Store link; for an OSS project shipped worldwide, list a few apps neutrally and point at
// the device's own store instead of a region-locked link.
return [
    'modal' => [
        'description' => 'この設定には、TOTP に対応した認証アプリ（Google Authenticator、Microsoft Authenticator、1Password など）が必要です。お使いの端末のアプリストアからインストールしてください。',
    ],
];
