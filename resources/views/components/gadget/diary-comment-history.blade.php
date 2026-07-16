{{-- OpenPNE 3 diaryCommentHistory (diaryComment/_history): other members' diaries the viewer
     commented on, latest comment first; dropped entirely when empty. Rows carry the author name
     (withName) and the last-comment date, and no camera marker (withIcon=false). No "More" link:
     OpenPNE 3's diary_comment_history page is intentionally not ported (route parity gap). --}}
@if (count($entries))
    <div class="dparts homeRecentList"@if ($partId !== null) id="{{ $partId }}"@endif><div class="parts">
        <div class="partsHeading"><h3>{{ __('%Diary% Comment History') }}</h3></div>
        <div class="block">
            @include('components.gadget._diary-article-rows', ['entries' => $entries, 'withName' => true, 'withIcon' => false])
        </div>
    </div></div>
@endif
