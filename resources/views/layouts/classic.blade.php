<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title') | {{ sns_title() ?: sns_name() }}</title>
    {{-- Default skin, served statically. $classicSkinCss is the seam a future theme resolver injects. --}}
    <link rel="stylesheet" href="{{ $classicSkinCss ?? asset('opSkinBasicPlugin/css/main.css') }}">
</head>
<body id="{{ $pageId ?? '' }}" class="{{ $pageClass ?? 'secure_page' }}">
<div id="Body">
    <div id="Container">
        <div id="Header">
            <div id="HeaderContainer">
                <h1 id="logo"><a href="{{ url('/') }}">{{ sns_name() }}</a></h1>
                @include('layouts.partials.global-nav')
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

                {{-- OpenPNE 3 layout letter from the zones present: A has a `top` row, B a `sidemenu`
                     column, C neither (gadget pages feed top/sidemenu/bottom; other pages only
                     content/sidemenu, so they stay B/C exactly as before). --}}
                @php($layout = $layout ?? match (true) {
                    \Illuminate\Support\Facades\View::hasSection('top') => 'A',
                    \Illuminate\Support\Facades\View::hasSection('sidemenu') => 'B',
                    default => 'C',
                })
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
