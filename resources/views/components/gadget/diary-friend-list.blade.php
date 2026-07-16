{{-- OpenPNE 3 diaryFriendList (_friendDiaryList): dropped entirely when empty, so a friendless
     viewer gets no orphan heading. Rows carry the author name (withName). --}}
@if (count($entries))
    <div class="dparts homeRecentList"@if ($partId !== null) id="{{ $partId }}"@endif><div class="parts">
        <div class="partsHeading"><h3>{{ __('Recently Posted %Diaries% of %My_friends%') }}</h3></div>
        <div class="block">
            @include('components.gadget._diary-article-rows', ['entries' => $entries, 'withName' => true])
            <div class="moreInfo"><ul class="moreInfo">
                <li><a href="{{ route('diary.list_friend') }}">{{ __('More') }}</a></li>
            </ul></div>
        </div>
    </div></div>
@endif
