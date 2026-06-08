<?php

declare(strict_types=1);

return [
    'reset' => 'パスワードが再設定されました。',
    'sent' => 'パスワードリセットメールを送信しました。',
    'throttled' => '時間を置いて再度お試しください。',
    'token' => 'このパスワード再設定トークンは無効です。',
    'user' => 'このメールアドレスに一致するユーザーがいません。',
    // Shown for every forgot-password request, registered or not, so the reply never reveals whether
    // an address has an account.
    'neutral' => 'そのメールアドレスのアカウントが登録されている場合、パスワード再設定用のリンクをお送りしました。届くまで数分かかることがあります。迷惑メールフォルダもご確認ください。',
];
