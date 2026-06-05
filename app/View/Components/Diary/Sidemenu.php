<?php

namespace App\View\Components\Diary;

use App\Features\Diary\DiaryCalendar;
use App\Features\Diary\Queries\MemberDiaryDays;
use App\Features\Diary\Queries\RecentMemberDiaries;
use App\Models\Diary;
use App\Models\Member;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * OpenPNE 3 diary sidemenu (get_component('diary','sidemenu')) for the Classic LayoutB Left
 * column: the author's identity (avatar, when set, linked to their profile), a month calendar
 * linking days that have diaries to the day archive, and the author's recent diaries. All scoped
 * to the current viewer (a guest reaching a web-public page sees only web-public entries).
 *
 * `year`/`month` focus the calendar (OpenPNE 3 passes the diary's or archive's month); without
 * them it shows the current month, as OpenPNE 3 does on the new/edit forms.
 */
class Sidemenu extends Component
{
    public DiaryCalendar $calendar;

    /** @var list<int> */
    public array $diaryDays;

    /** @var Collection<int, Diary> */
    public Collection $recentDiaries;

    public function __construct(
        public Member $member,
        RecentMemberDiaries $recent,
        MemberDiaryDays $diaryDays,
        ?int $year = null,
        ?int $month = null,
    ) {
        $now = CarbonImmutable::now();
        $year ??= $now->year;
        $month ??= $now->month;

        $viewer = $this->viewer();
        $this->calendar = DiaryCalendar::forMonth($year, $month);
        $this->diaryDays = $diaryDays($viewer, $member, $year, $month);
        $this->recentDiaries = $recent($viewer, $member);
    }

    public function render(): View
    {
        return view('components.diary.sidemenu');
    }

    private function viewer(): ?Member
    {
        $user = auth()->user();

        return $user instanceof Member ? $user : null;
    }
}
