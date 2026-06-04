{{-- OpenPNE 3 diary _sidemenu.php Left column. memberImageBox's avatar (p.photo) waits on
     FileStorage, so the name carries the profile link; the calendar box is a follow-up. --}}
<div class="parts memberImageBox">
    <p class="text"><a href="{{ route('member.profile.show', $member) }}">{{ $member->name }}</a></p>
</div>

@if ($recentDiaries->isNotEmpty())
    <div class="parts pageNav">
        <div class="partsHeading"><h3>{{ __('Recently Posted %Diaries%') }}</h3></div>
        <ul>
            @foreach ($recentDiaries as $entry)
                {{-- OpenPNE 3 op_diary_get_title_and_count: title (truncated) + comment count. --}}
                <li><a href="{{ route('diary.show', $entry) }}">{{ str($entry->title)->limit(36) }} ({{ $entry->comments_count }})</a></li>
            @endforeach
        </ul>
    </div>
@endif
