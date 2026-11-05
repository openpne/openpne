{{-- OpenPNE 3 recentCommunityTopicComment: recent topics across the viewer's joined groups,
     dropped entirely when empty. --}}
@if (count($entries))
    <div class="dparts homeRecentList"@if ($partId !== null) id="{{ $partId }}"@endif><div class="parts">
        <div class="partsHeading"><h3>{{ __('Recently Posted %Community% %Topics%') }}</h3></div>
        <div class="block">
            @include('components.gadget._group-recent-rows', ['entries' => $entries])
            <div class="moreInfo"><ul class="moreInfo"><li><a href="{{ route('group.topics.recent') }}">{{ __('More') }}</a></li></ul></div>
        </div>
    </div></div>
@endif
