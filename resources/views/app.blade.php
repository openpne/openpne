<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#2563eb">
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
