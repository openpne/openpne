@php
    // The per-site brand color overrides --primary / --primary-foreground only, as an inline style so
    // it outranks both the :root and .dark declarations (and Vite's late-injected stylesheet in dev).
    // data-theme-color-light is read by lib/color-mode.ts, which owns the chrome color after hydration.
    $brandColor = brand_color();
    $brandForeground = $brandColor === null ? null : \App\Support\BrandColor::readableForeground($brandColor);
    $themeColor = $brandColor ?? \App\Support\BrandColor::DEFAULT;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme-color-light="{{ $themeColor }}"@if ($brandColor) style="--primary: {{ $brandColor }}; --primary-foreground: {{ $brandForeground }}"@endif>
<head>
    <meta charset="utf-8">
    {{-- viewport-fit=cover: without it iOS insets the viewport itself and reports 0 for every
         env(safe-area-inset-*), so the Modern chrome's safe-area padding would never apply. The
         chrome pads for the insets itself; Classic has no fixed bottom bar and keeps the default. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="{{ $themeColor }}">
    @if ($brandFavicon = brand_favicon_url())
        <link rel="icon" type="image/png" href="{{ $brandFavicon }}">
    @else
        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" type="image/png" href="{{ asset('favicon-32x32.png') }}" sizes="32x32">
    @endif
    <link rel="apple-touch-icon" href="{{ app_icon_url(180) }}">
    <link rel="manifest" href="{{ route('webmanifest') }}">
    {{-- Apply the saved color mode before first paint so a dark-mode member never sees a light flash.
         lib/color-mode.ts keeps the class in sync after mount. --}}
    <script>
        (function () {
            try {
                var p = localStorage.getItem('openpne-color-mode');
                if (p !== 'light' && p !== 'dark') p = 'system';
                if (p === 'dark' || (p === 'system' && matchMedia('(prefers-color-scheme: dark)').matches)) {
                    document.documentElement.classList.add('dark');
                }
            } catch (e) {}
        })();
    </script>
    <title inertia>{{ sns_title() ?: sns_name() }}</title>
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @inertiaHead
</head>
{{-- dvh, not vh: 100vh is the URL-bar-hidden height on mobile, so a page that fits the visible
     viewport would still scroll by the height of the browser chrome. --}}
<body class="min-h-dvh bg-background font-sans text-foreground antialiased">
    @inertia
</body>
</html>
