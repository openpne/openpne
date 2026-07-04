<?php

// Overrides only the set-up modal description (merged over the package strings by Laravel's
// recursive namespace override). Filament's default names a single product (Google Authenticator)
// with a US-fixed App Store link, which is neither neutral nor durable — the app landscape shifts
// (Authy's desktop app was discontinued in 2024). Point the operator at their own store's search
// instead: it stays current and privileges no product. "TOTP" is dropped as jargon.
return [
    'modal' => [
        'description' => 'ログイン時にワンタイムコードを生成する認証アプリが必要です。お使いの端末のアプリストアで「認証アプリ」または「Authenticator」と検索してインストールしてください。',
    ],
];
