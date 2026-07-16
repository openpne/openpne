{{-- OpenPNE 3 recentCommunityTopicComment: recent topics across the viewer's joined communities,
     dropped entirely when empty. No "More" link — OpenPNE 3's recent-list link target is
     intentionally not ported (route parity gap). --}}
@if (count($entries))
    <div class="dparts homeRecentList"@if ($partId !== null) id="{{ $partId }}"@endif><div class="parts">
        <div class="partsHeading"><h3>{{ __('Recently Posted %Community% %Topics%') }}</h3></div>
        <div class="block">
            @include('components.gadget._community-recent-rows', ['entries' => $entries])
        </div>
    </div></div>
@endif
