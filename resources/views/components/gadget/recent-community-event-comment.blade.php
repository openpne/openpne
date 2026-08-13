{{-- OpenPNE 3 recentCommunityEventComment: recent events across the viewer's joined groups,
     dropped entirely when empty. --}}
@if (count($entries))
    <div class="dparts homeRecentList"@if ($partId !== null) id="{{ $partId }}"@endif><div class="parts">
        <div class="partsHeading"><h3>{{ __('Recently Posted %Community% Events') }}</h3></div>
        <div class="block">
            @include('components.gadget._community-recent-rows', ['entries' => $entries])
        </div>
    </div></div>
@endif
