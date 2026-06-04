{{-- OpenPNE 3 diary _sidemenu.php Left column. memberImageBox's avatar (p.photo) waits on
     FileStorage, so the name carries the profile link. --}}
<div class="parts memberImageBox">
    <p class="text"><a href="{{ route('member.profile.show', $member) }}">{{ $member->name }}</a></p>
</div>

<div class="parts calendar">
    <div class="partsHeading"><h3>
        <a href="{{ route('diary.list_member.archive', ['member' => $member, ...$calendar->previousMonth()]) }}">&lt;&lt;</a>
        {{ $calendar->label() }}
        <a href="{{ route('diary.list_member.archive', ['member' => $member, ...$calendar->nextMonth()]) }}">&gt;&gt;</a>
    </h3></div>
    <table class="calendar"><tbody>
        <tr>
            <th class="sun">{{ __('Sun') }}</th>
            <th class="mon">{{ __('Mon') }}</th>
            <th class="tue">{{ __('Tue') }}</th>
            <th class="wed">{{ __('Wed') }}</th>
            <th class="thu">{{ __('Thu') }}</th>
            <th class="fri">{{ __('Fri') }}</th>
            <th class="sat">{{ __('Sat') }}</th>
        </tr>
        @foreach ($calendar->weeks as $week)
            <tr>
                @foreach ($week as $day)
                    <td>
                        @if ($day !== null)
                            @if (in_array($day, $diaryDays, true))
                                <a href="{{ route('diary.list_member.archive', ['member' => $member, 'year' => $calendar->year, 'month' => $calendar->month, 'day' => $day]) }}">{{ $day }}</a>
                            @else
                                {{ $day }}
                            @endif
                        @endif
                    </td>
                @endforeach
            </tr>
        @endforeach
    </tbody></table>
</div>

@if ($recentDiaries->isNotEmpty())
    <div class="parts pageNav">
        <div class="partsHeading"><h3>{{ __('Recently Posted %Diaries%') }}</h3></div>
        <ul>
            @foreach ($recentDiaries as $entry)
                {{-- OpenPNE 3 op_diary_get_title_and_count: truncated title + comment count. --}}
                <li><a href="{{ route('diary.show', $entry) }}">{{ \App\Features\Diary\DiaryTitle::withCount($entry) }}</a></li>
            @endforeach
        </ul>
    </div>
@endif
