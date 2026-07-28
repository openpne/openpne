{{-- OpenPNE 3 `default/errorSuccess.php` (403/404) and `default/csrfErrorSuccess.php` (419): the
     message is bare text, not a box, followed by the history-back line. Rendered only through
     App\Support\ClassicErrorPage — the framework's own error pages stay in place for everything
     this screen does not cover. --}}
@extends('layouts.classic')

{{-- The OpenPNE 3 module/action hook for this screen. No route resolves here (the request matched
     none, or failed before its action ran), so it cannot come from the route parity. --}}
@php($pageId = 'page_default_error')
@php($pageClass = auth()->check() ? 'secure_page' : 'insecure_page')
{{-- OpenPNE 3's default module declares no layout or stylesheet of its own: the global layoutC,
     and no plugin CSS even when the URL that failed belongs to a plugin's module. --}}
@php($layout = 'C')
@php($suppressPluginCss = true)
@php($message = $status === 419 ? __('CSRF attack detected.') : __("You can't access this page."))

@section('title', $message)

@section('content')
    {{ $message }}

    <x-classic.parts id="backLink" name="line">
        <a href="#" onclick="history.back(); return false;">{{ __('Back to previous page') }}</a>
    </x-classic.parts>
@endsection
