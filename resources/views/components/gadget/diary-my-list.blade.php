{{-- OpenPNE 3 diaryMyList (_myDiaryList): the one diary gadget whose frame always renders, even
     with no entries — the empty state still offers the "write a diary" link. The "More" link only
     appears when there are entries. Own diaries, so no author name. --}}
<div class="dparts homeRecentList"@if ($partId !== null) id="{{ $partId }}"@endif><div class="parts">
    <div class="partsHeading"><h3>{{ __('My %diaries%') }}</h3></div>
    <div class="block">
        @if (count($entries))
            @include('components.gadget._diary-article-rows', ['entries' => $entries])
        @endif
        <div class="moreInfo"><ul class="moreInfo">
            @if (count($entries))
                <li><a href="{{ route('diary.list_member') }}">{{ __('More') }}</a></li>
            @endif
            <li><a href="{{ route('diary.new') }}">{{ __('Write a %diary%') }}</a></li>
        </ul></div>
    </div>
</div></div>
