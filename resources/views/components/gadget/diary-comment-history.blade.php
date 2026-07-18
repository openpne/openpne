{{-- OpenPNE 3 diaryCommentHistory (diaryComment/_history): other members' diaries the viewer
     commented on, latest comment first; dropped entirely when empty. Rows carry the author name
     (withName) and the last-comment date, and no camera marker (withIcon=false). --}}
@if (count($entries))
    <div class="dparts homeRecentList"@if ($partId !== null) id="{{ $partId }}"@endif><div class="parts">
        <div class="partsHeading"><h3>{{ __('%Diary% Comment History') }}</h3></div>
        <div class="block">
            @include('components.gadget._diary-article-rows', ['entries' => $entries, 'withName' => true, 'withIcon' => false])
        </div>
    </div></div>
@endif
