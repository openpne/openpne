{{-- OpenPNE 3 diary/_sidemenu.php hand-writes all three boxes as single parts with no id, so the
     calendar and pageNav kinds — which the parts helper would render as dparts — stay single here. --}}

{{-- The author's avatar box (always rendered, no_image fallback when unset) linked to their
     profile, then their name. --}}
<x-classic.parts name="memberImageBox">
    @php($avatar = $member->avatar?->file)
    <p class="photo"><a href="{{ route('member.profile.show', $member) }}"><x-classic.image :file="$avatar" :size="120" :alt="$member->name" /></a></p>
    <p class="text"><a href="{{ route('member.profile.show', $member) }}">{{ $member->name }}</a></p>
</x-classic.parts>

<x-classic.parts name="calendar" :single="true">
    <x-slot:heading>
        <h3>
            <a href="{{ route('diary.list_member.archive', ['member' => $member, ...$calendar->previousMonth()]) }}">&lt;&lt;</a>
            {{ $calendar->label() }}
            <a href="{{ route('diary.list_member.archive', ['member' => $member, ...$calendar->nextMonth()]) }}">&gt;&gt;</a>
        </h3>
    </x-slot:heading>
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
</x-classic.parts>

@if ($recentDiaries->isNotEmpty())
    <x-classic.parts name="pageNav" :single="true" :title="__('Recently Posted %Diaries%')">
        <ul>
            @foreach ($recentDiaries as $entry)
                <li><a href="{{ route('diary.show', $entry) }}">{{ \App\Features\Diary\DiaryTitle::withCount($entry) }}</a></li>
            @endforeach
        </ul>
    </x-classic.parts>
@endif
