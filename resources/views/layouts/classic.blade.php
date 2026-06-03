<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title') | {{ config('app.name') }}</title>
    {{-- Default skin, served statically. $classicSkinCss is the seam a future theme resolver injects. --}}
    <link rel="stylesheet" href="{{ $classicSkinCss ?? asset('opSkinBasicPlugin/css/main.css') }}">
</head>
<body id="{{ $pageId ?? '' }}" class="{{ $pageClass ?? 'secure_page' }}">
<div id="Body">
    <div id="Container">
        <div id="Header">
            <div id="HeaderContainer">
                <h1 id="logo"><a href="{{ url('/') }}">{{ config('app.name') }}</a></h1>
                {{-- A guest reaches the Classic layout on a web-public profile, so the member
                     nav is auth-gated (OpenPNE 3 split secure/insecure nav the same way). --}}
                <div id="globalNav">
                    <ul>
                        <li id="globalNav_home"><a href="{{ url('/') }}">{{ __('Home') }}</a></li>
                        @auth
                            <li id="globalNav_diary"><a href="{{ route('diary.list_member') }}">{{ __('%Diary%') }}</a></li>
                            <li id="globalNav_friend"><a href="{{ route('friend.list') }}">{{ __('%Friends%') }}</a></li>
                            <li id="globalNav_search"><a href="{{ route('member.search') }}">{{ __('Member search') }}</a></li>
                            <li id="globalNav_block"><a href="{{ route('block.list') }}">{{ __('Blocked members') }}</a></li>
                            <li id="globalNav_profile"><a href="{{ route('member.profile.mine_compat') }}">{{ __('My profile') }}</a></li>
                            <li id="globalNav_logout">
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit">{{ __('Log Out') }}</button>
                                </form>
                            </li>
                        @else
                            <li id="globalNav_login"><a href="{{ route('login') }}">{{ __('Log In') }}</a></li>
                        @endauth
                    </ul>
                </div>
            </div><!-- HeaderContainer -->
        </div><!-- Header -->

        <div id="Contents">
            <div id="ContentsContainer">
                {{-- localNav is OpenPNE 3's secondary nav bar (admin Navigation data, secure
                     pages only). The admin-configurable data and the friend/community contexts
                     are deferred; this renders the shipped `default` set. --}}
                <div id="localNav">
                    @auth
                        <ul class="default">
                            <li id="default_home"><a href="{{ url('/') }}">{{ __('Home') }}</a></li>
                            <li id="default_friend"><a href="{{ route('friend.list') }}">{{ __('My Friends') }}</a></li>
                            <li id="default_diary"><a href="{{ route('diary.list_member') }}">{{ __('%Diary%') }}</a></li>
                            <li id="default_profile"><a href="{{ route('member.profile.mine_compat') }}">{{ __('My profile') }}</a></li>
                            <li id="default_editProfile"><a href="{{ route('member.profile.edit') }}">{{ __('Edit Profile') }}</a></li>
                        </ul>
                    @endauth
                </div>

                <div id="Layout{{ $layout ?? 'C' }}" class="Layout">
                    {{-- OpenPNE 3 alertBox markup so the ported skin styles flash messages. --}}
                    @if (session('error'))
                        <div class="alertBox">
                            <table><tr><th></th><td role="alert">{{ session('error') }}</td></tr></table>
                        </div>
                    @endif
                    @if (session('status'))
                        <div class="alertBox">
                            <table><tr><th></th><td role="status">{{ session('status') }}</td></tr></table>
                        </div>
                    @endif

                    <div id="Center">
                        @yield('content')
                    </div><!-- Center -->
                </div><!-- Layout -->
            </div><!-- ContentsContainer -->
        </div><!-- Contents -->

        <div id="Footer">
            <div id="FooterContainer">
                {{-- Trusted admin/operator HTML (OpenPNE 3 SnsConfig footer). $classicFooterHtml
                     is the seam a future admin resolver injects; config is the default. --}}
                @php($footerHtml = $classicFooterHtml ?? config('openpne.classic.footer_html'))
                @if ($footerHtml)
                    <p>{!! $footerHtml !!}</p>
                @endif
            </div>
        </div><!-- Footer -->
    </div><!-- Container -->
</div><!-- Body -->
</body>
</html>
