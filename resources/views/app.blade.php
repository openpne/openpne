<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    {{-- viewport-fit=cover: without it iOS insets the viewport itself and reports 0 for every
         env(safe-area-inset-*), so the Modern chrome's safe-area padding would never apply. The
         chrome pads for the insets itself; Classic has no fixed bottom bar and keeps the default. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#2563eb">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" href="{{ asset('favicon-32x32.png') }}" sizes="32x32">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
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
<body class="min-h-screen bg-background font-sans text-foreground antialiased">
    @inertia
</body>
</html>
