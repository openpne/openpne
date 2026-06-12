{{-- OpenPNE 3 globalNav (admin Navigation data): the `secure_global` set for members, the
     `insecure_global` set for guests (a guest reaches the Classic layout on a web-public profile).
     OpenPNE 3 ships no insecure_global rows, so when the guest set is empty we keep a Log In link
     as a fallback entry into the site. --}}
@php
    $navType = auth()->check() ? 'secure_global' : 'insecure_global';
    $navItems = app(\App\Services\NavigationService::class)->visibleEntries($navType, app()->getLocale());
@endphp
<div id="globalNav">
    <ul>
        @foreach ($navItems as $item)
            @include('layouts.partials.nav-item', ['item' => $item])
        @endforeach
        @guest
            @if (empty($navItems))
                <li id="globalNav_login"><a href="{{ route('login') }}">{{ __('Log In') }}</a></li>
            @endif
        @endguest
    </ul>
</div>
