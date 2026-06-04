<?php

namespace App\View\Components\Diary;

use App\Features\Diary\Queries\RecentMemberDiaries;
use App\Models\Diary;
use App\Models\Member;
use Illuminate\Support\Collection;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * OpenPNE 3 diary sidemenu (get_component('diary','sidemenu')) for the Classic LayoutB Left
 * column: the author's identity and their recent diaries, scoped to the current viewer (a
 * guest reaching a web-public page sees only web-public entries). The calendar archive box is
 * a follow-up; the avatar waits on FileStorage.
 */
class Sidemenu extends Component
{
    /** @var Collection<int, Diary> */
    public Collection $recentDiaries;

    public function __construct(public Member $member, RecentMemberDiaries $recent)
    {
        $this->recentDiaries = $recent($this->viewer(), $member);
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
