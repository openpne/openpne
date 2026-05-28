<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title') | {{ config('app.name') }}</title>
</head>
<body id="{{ $pageId ?? '' }}" class="{{ $pageClass ?? 'secure_page' }}">
    <div id="container">
        <div id="header">
            <h1 id="logo"><a href="{{ url('/') }}">{{ config('app.name') }}</a></h1>
            <ul id="globalNav">
                <li><a href="{{ route('friend.list') }}">Friends</a></li>
                <li><a href="{{ route('friend.manage') }}">Pending requests</a></li>
            </ul>
        </div>

        <div id="layoutA" class="layoutA">
            <div id="localNav"></div>

            <div id="main" role="main">
                @if (session('status'))
                    <p class="message" role="status">{{ session('status') }}</p>
                @endif

                @if (session('error'))
                    <p class="message error" role="alert">{{ session('error') }}</p>
                @endif

                @yield('content')
            </div>
        </div>

        <div id="footer"></div>
    </div>
</body>
</html>
