{{-- A 5xx page reads nothing but config: the database is what tends to be broken when one is shown. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') - {{ config('app.name') }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f8f7f4; color: #1c1917; font-family: system-ui, sans-serif; }
        main { max-width: 28rem; padding: 2rem 1.5rem; text-align: center; }
        h1 { font-size: 1.25rem; margin: 0 0 .75rem; }
        p { margin: 0 0 1.5rem; color: #57534e; line-height: 1.6; }
        a { color: #1d4ed8; }
        .code { display: block; font-size: .875rem; color: #a8a29e; margin-bottom: 1rem; }
    </style>
</head>
<body>
    <main>
        <span class="code">@yield('code') · {{ config('app.name') }}</span>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>
        <a href="{{ url('/') }}">{{ __('Back to the top page') }}</a>
    </main>
</body>
</html>
