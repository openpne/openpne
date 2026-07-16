{{-- OpenPNE 3 diaryMemberList (_memberDiaryList): the profile-page variant. OpenPNE 3 emitted no
     DOM id on the wrapper here (class-only .homeRecentList), so it carries none. Dropped entirely
     when empty. The owner's own diaries, so no author name. --}}
@if (count($entries))
    <div class="dparts homeRecentList"><div class="parts">
        <div class="partsHeading"><h3>{{ __('Recently Posted %Diaries%') }}</h3></div>
        <div class="block">
            @include('components.gadget._diary-article-rows', ['entries' => $entries])
            <div class="moreInfo"><ul class="moreInfo">
                <li><a href="{{ route('diary.list_member', $subject) }}">{{ __('More') }}</a></li>
            </ul></div>
        </div>
    </div></div>
@endif
