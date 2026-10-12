<a href="{{ url('/') }}" class="brand">{{ sns_name() }}</a>
@if (request()->user('member') !== null)
    @include('layouts.partials.notification-center-assets')
@endif
<ul class="nav" id="localNav">
    @include('layouts.partials.notification-center')
    @include('layouts.partials.local-nav', ['bare' => true])
</ul>
@include('layouts.partials.push-reconcile')
@include('layouts.partials.global-nav')
<div id="topBanner">{!! classic_top_banner() !!}</div>
