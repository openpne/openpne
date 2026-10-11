{{-- The theme plugin's _header.php: the brand link, the local nav as a navbar list that holds the
     notification center, the global nav folded into one dropdown, then the banner. --}}
<a href="{{ url('/') }}" class="brand">{{ sns_name() }}</a>
{{-- The center's style and deferred script are claimed here, ahead of the list it sits in as an item. --}}
@if (request()->user('member') !== null)
    @once('classic-notification-center-style')
        <style>
            .ncbuttonLink { text-decoration: none; }
            .notificationCenterRowLink { padding: 0; border: 0; background: none; font: inherit; color: inherit; text-align: left; cursor: pointer; }
        </style>
    @endonce
    @once('classic-notification-center-script')
        <script src="{{ asset('js/classic-notification-center.js') }}" defer></script>
    @endonce
@endif
<ul class="nav" id="localNav">
    @include('layouts.partials.notification-center')
    @include('layouts.partials.local-nav', ['bare' => true])
</ul>
@include('layouts.partials.push-reconcile')
@include('layouts.partials.global-nav')
<div id="topBanner">{!! classic_top_banner() !!}</div>
