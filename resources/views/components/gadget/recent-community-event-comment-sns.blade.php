{{-- OpenPNE 3 recentCommunityEventCommentSns: recent events across every public community, dropped
     entirely when empty. OpenPNE 3 renders this via op_include_parts, so the parts-name class
     (eventRecentList) is added. No "More" link — the recent-list link target is not ported (parity gap). --}}
@if (count($entries))
    <div class="dparts eventRecentList homeRecentList"@if ($partId !== null) id="{{ $partId }}"@endif><div class="parts">
        <div class="partsHeading"><h3>{{ __('Latest %community% events across the SNS') }}</h3></div>
        <div class="block">
            @include('components.gadget._community-recent-rows', ['entries' => $entries])
        </div>
    </div></div>
@endif
