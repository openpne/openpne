{{-- The globalNav bar (admin Navigation data): the `secure_global` set for members, the
     `insecure_global` set for guests (a guest reaches the Classic layout on a web-public profile).
     The guest set is empty by default, so a Log In link is kept as a fallback entry into the
     site. --}}
@php
    $navType = auth()->check() ? 'secure_global' : 'insecure_global';
    $navItems = app(\App\Services\NavigationService::class)->visibleEntries($navType, app()->getLocale());
@endphp
@once
    {{-- The logout tab is a POST form button (nav-item), dressed as the plain tab OpenPNE 3 drew;
         mirrors the skin's #globalNav li a rules so the header reads as one row of tabs.
         :where() zeroes the selector's specificity: this block sits after a site's own CSS, so
         any ordinary selector of theirs must still win. font: inherit leads the block — as a
         shorthand it would reset a line-height declared before it. --}}
    <style>
        :where(#globalNav li form) { margin: 0; }
        :where(#globalNav li form button) { font: inherit; padding: 0 8px; height: 40px; line-height: 40px; display: block; color: #FFFFFF; text-decoration: none; background: none; border: 0; cursor: pointer; }
        :where(#globalNav li form button:hover) { background: transparent url({{ asset('opSkinBasicPlugin/images/bg_globalnav_hover.gif') }}) repeat-x scroll 0 0; }
    </style>
@endonce
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
