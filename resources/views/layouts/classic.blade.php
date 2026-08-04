<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- The admin favicon applies to both surfaces; the brand color and logo are Modern-only. --}}
    @if ($brandFavicon = brand_favicon_url())
        <link rel="icon" type="image/png" href="{{ $brandFavicon }}">
    @else
        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" type="image/png" href="{{ asset('favicon-32x32.png') }}" sizes="32x32">
    @endif
    <link rel="apple-touch-icon" href="{{ app_icon_url(180) }}">
    <link rel="manifest" href="{{ route('webmanifest') }}">
    <title>@yield('title') | {{ sns_title() ?: sns_name() }}</title>
    {{-- Default skin, served statically; $classicSkinCss overrides which skin stylesheet is linked. --}}
    <link rel="stylesheet" href="{{ $classicSkinCss ?? asset('opSkinBasicPlugin/css/main.css') }}">
    {{-- The page module's OpenPNE 3 plugin stylesheet, linked after the skin it overrides.
         $suppressPluginCss is for a screen rendered over a route it is not the screen of (the
         error page): the route's module would otherwise lend it a stylesheet it never had. --}}
    @php($pluginCssUrl = ($suppressPluginCss ?? false) ? null : classic_plugin_css_url())
    @if ($pluginCssUrl)
        <link rel="stylesheet" href="{{ $pluginCssUrl }}">
    @endif
    {{-- Plugin stylesheets a screen loads through an embedded component rather than its module's
         view.yml (OpenPNE 3 addStylesheet from the partial): pushed by the page, same cascade
         slot as the module link above. --}}
    @stack('pluginCss')
    {{-- Admin custom CSS, linked after the skin so it overrides it. --}}
    @if ($customCssUrl = classic_custom_css_url())
        <link rel="stylesheet" href="{{ $customCssUrl }}">
    @endif
    {{-- Operator HTML insertion in <head>; admin-trusted, output raw. --}}
    {!! classic_html_slot('head') !!}
</head>
<body id="{{ $pageId ?? '' }}" class="{{ $pageClass ?? 'secure_page' }}">
{{-- Operator HTML insertion just inside <body>. --}}
{!! classic_html_slot('top2') !!}
<div id="Body">
{{-- Operator HTML insertion at the top of #Body. --}}
{!! classic_html_slot('top') !!}
    <div id="Container">
        <div id="Header">
            <div id="HeaderContainer">
                <h1 id="logo"><a href="{{ url('/') }}">{{ sns_name() }}</a></h1>
                @include('layouts.partials.notification-center')
                @include('layouts.partials.global-nav')
                {{-- Operator banner shown above the content, by login state. --}}
                <div id="topBanner">{!! classic_top_banner() !!}</div>
            </div><!-- HeaderContainer -->
        </div><!-- Header -->

        <div id="Contents">
            <div id="ContentsContainer">
                {{-- The secondary nav bar (admin Navigation data, secure pages only): the
                     `default` set on own pages, the `friend` set when viewing another member.
                     See layouts.partials.local-nav. --}}
                <div id="localNav">
                    @include('layouts.partials.local-nav')
                </div>

                {{-- Gadget pages (home/profile/login) pass the configured layout's letter; every
                     other Classic screen resolves it from the route-parity registry, defaulting
                     to layoutC. --}}
                @php($layout = $layout ?? classic_layout())
                <div id="Layout{{ $layout }}" class="Layout">
                    {{-- OpenPNE 3's two flash slots, as its `alertBox` parts (`#flashError` /
                         `#flashNotice` are customization ids skins and customer CSS target). The
                         icon cell is decorative — the message text is what a screen reader gets,
                         through the role OpenPNE 3 had no equivalent of. --}}
                    @if (session('error'))
                        <x-classic.parts id="flashError" name="alertBox">
                            <table><tr><th><img src="{{ asset('images/icon_alert.gif') }}" alt=""></th><td role="alert">{{ session('error') }}</td></tr></table>
                        </x-classic.parts>
                    @endif
                    @if (session('status'))
                        <x-classic.parts id="flashNotice" name="alertBox">
                            <table><tr><th><img src="{{ asset('images/icon_alert.gif') }}" alt=""></th><td role="status">{{ session('status') }}</td></tr></table>
                        </x-classic.parts>
                    @endif

                    @hasSection('top')
                        <div id="Top">
                            @yield('top')
                        </div><!-- Top -->
                    @endif

                    @hasSection('sidemenu')
                        <div id="Left">
                            @yield('sidemenu')
                        </div><!-- Left -->
                    @endif

                    <div id="Center">
                        @yield('content')
                    </div><!-- Center -->

                    @hasSection('bottom')
                        <div id="Bottom">
                            @yield('bottom')
                        </div><!-- Bottom -->
                    @endif
                </div><!-- Layout -->

                @include('layouts.partials.side-banner')
            </div><!-- ContentsContainer -->
        </div><!-- Contents -->

        <div id="Footer">
            <div id="FooterContainer">
                {{-- Trusted admin/operator HTML, chosen by the page's secure_page/insecure_page
                     class. $classicFooterHtml stays a per-request override seam. --}}
                @php($footerHtml = $classicFooterHtml ?? classic_footer_html(($pageClass ?? 'secure_page') !== 'insecure_page'))
                @if ($footerHtml)
                    <p>{!! $footerHtml !!}</p>
                @endif
            </div>
        </div><!-- Footer -->

        {{-- Operator HTML insertion before #Container closes. --}}
        {!! classic_html_slot('bottom2') !!}
    </div><!-- Container -->
    {{-- Operator HTML insertion before #Body closes. --}}
    {!! classic_html_slot('bottom') !!}
</div><!-- Body -->
</body>
</html>
