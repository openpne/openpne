{{-- The globalNav bar (admin Navigation data): the `secure_global` set for members, the
     `insecure_global` set for guests (a guest reaches the Classic layout on a web-public profile).
     The guest set is empty by default, so a Log In link is kept as a fallback entry into the
     site. --}}
@php
    $navType = auth()->check() ? 'secure_global' : 'insecure_global';
    $navItems = app(\App\Services\NavigationService::class)->visibleEntries($navType, app()->getLocale());
@endphp
@if (classic_theme() !== null)
    {{-- The li repeats its container's id because the theme stylesheets address `li#globalNav`;
         without script the menu stands open, since Settings, Invite and Log out live only there. --}}
    @once
        <style>
            :where(#globalNav .dropdown-menu li form) { margin: 0; }
            :where(#globalNav .dropdown-menu li form button) { font: inherit; display: block; width: 100%; padding: 3px 20px; text-align: left; color: inherit; background: none; border: 0; cursor: pointer; }
        </style>
        <noscript><style>#globalNav .dropdown-menu { display: block; position: static; float: none; }</style></noscript>
    @endonce
    <div id="globalNav">
        <ul class="nav pull-right">
            @if (! empty($navItems))
                <li id="globalNav" class="dropdown">
                    <a href="#" class="dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        <p>menu</p><i class="fa fa-sort-asc"></i>
                    </a>
                    <ul class="dropdown-menu">
                        @foreach ($navItems as $item)
                            @include('layouts.partials.nav-item', ['item' => $item['domId'] === 'globalNav__member_profile_mine' ? ['label' => auth()->user()->name] + $item : $item])
                        @endforeach
                    </ul>
                </li>
            @endif
            @guest
                @if (empty($navItems))
                    <li id="globalNav_login"><a href="{{ route('login') }}">{{ __('Log In') }}</a></li>
                @endif
            @endguest
        </ul>
    </div>
    @once
        <script src="{{ asset('js/classic-theme-nav.js') }}" defer></script>
    @endonce
@else
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
@endif
