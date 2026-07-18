{{-- OpenPNE 3 recentCommunityTopicCommentSns: recent topics across every public community, dropped
     entirely when empty. OpenPNE 3 renders this via op_include_parts, so the parts-name class
     (topicRecentList) is added. --}}
@if (count($entries))
    <div class="dparts topicRecentList homeRecentList"@if ($partId !== null) id="{{ $partId }}"@endif><div class="parts">
        <div class="partsHeading"><h3>{{ __('Latest %community% %topics% across the SNS') }}</h3></div>
        <div class="block">
            @include('components.gadget._community-recent-rows', ['entries' => $entries])
        </div>
    </div></div>
@endif
