<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Confirm email change') }}</title>
</head>
<body>
    {{-- Token-gated landing for the emailed confirmation link. The change is the POST below, not this
         GET render, so a mail scanner / prefetch cannot consume the token. --}}
    <main id="page_member_email_change_confirm">
        <h1>{{ __('Confirm email change') }}</h1>
        <p>{{ __('Confirm changing your email address to :email.', ['email' => $newEmail]) }}</p>
        <form method="POST" action="{{ route('member.config.email.confirm.submit', ['token' => $token]) }}">
            @csrf
            <button type="submit">{{ __('Confirm email change') }}</button>
        </form>
    </main>
</body>
</html>
