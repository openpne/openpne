<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title') | {{ sns_title() ?: sns_name() }}</title>
    {{-- Default skin, served statically. $classicSkinCss is the seam a future theme resolver injects. --}}
    <link rel="stylesheet" href="{{ $classicSkinCss ?? asset('opSkinBasicPlugin/css/main.css') }}">
    {{-- Admin custom CSS (OpenPNE 3 customizing_css), linked after the skin so it overrides it. --}}
    @if ($customCssUrl = classic_custom_css_url())
        <link rel="stylesheet" href="{{ $customCssUrl }}">
    @endif
    {{-- Operator HTML insertion in <head> (OpenPNE 3 pc_html_head); admin-trusted, output raw. --}}
    {!! classic_html_slot('head') !!}
</head>
<body id="{{ $pageId ?? '' }}" class="{{ $pageClass ?? 'secure_page' }}">
{{-- Operator HTML insertion just inside <body> (OpenPNE 3 pc_html_top2). --}}
{!! classic_html_slot('top2') !!}
<div id="Body">
{{-- Operator HTML insertion at the top of #Body (OpenPNE 3 pc_html_top). --}}
{!! classic_html_slot('top') !!}
    <div id="Container">
        <div id="Header">
            <div id="HeaderContainer">
                <h1 id="logo"><a href="{{ url('/') }}">{{ sns_name() }}</a></h1>
                @include('layouts.partials.global-nav')
                {{-- OpenPNE 3 #topBanner: operator banner shown above the content, by login state. --}}
                <div id="topBanner">{!! classic_top_banner() !!}</div>
            </div><!-- HeaderContainer -->
        </div><!-- Header -->

        <div id="Contents">
            <div id="ContentsContainer">
                {{-- localNav is OpenPNE 3's secondary nav bar (admin Navigation data, secure
                     pages only): the `default` set on own pages, the `friend` set when viewing
                     another member. See layouts.partials.local-nav. --}}
                <div id="localNav">
                    @include('layouts.partials.local-nav')
                </div>

                {{-- OpenPNE 3 layout letter. Gadget pages (home/profile/login) pass the configured
                     layout's letter (OP3 setLayout); every other Classic screen resolves it from the
                     route-parity registry (OP3 view.yml / decorate_with), defaulting to layoutC. --}}
                @php($layout = $layout ?? classic_layout())
                <div id="Layout{{ $layout }}" class="Layout">
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
                {{-- Trusted admin/operator HTML (OpenPNE 3 footer_before/after), chosen by the page's
                     secure_page/insecure_page class, matching OpenPNE 3 isSecurePage. $classicFooterHtml
                     stays a per-request override seam. --}}
                @php($footerHtml = $classicFooterHtml ?? classic_footer_html(($pageClass ?? 'secure_page') !== 'insecure_page'))
                @if ($footerHtml)
                    <p>{!! $footerHtml !!}</p>
                @endif
            </div>
        </div><!-- Footer -->

        {{-- Operator HTML insertion before #Container closes (OpenPNE 3 pc_html_bottom2). --}}
        {!! classic_html_slot('bottom2') !!}
    </div><!-- Container -->
    {{-- Operator HTML insertion before #Body closes (OpenPNE 3 pc_html_bottom). --}}
    {!! classic_html_slot('bottom') !!}
</div><!-- Body -->
</body>
</html>
