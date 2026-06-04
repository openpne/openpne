<?php

namespace App\Features\Diary\Queries;

use App\Features\Diary\ArchivePeriod;
use App\Features\Diary\DiaryVisibilityScope;
use App\Models\Diary;
use App\Models\Member;

/**
 * Days of a month on which an author has a viewer-visible diary, for the sidemenu calendar
 * (OpenPNE 3 Diary::getMemberDiaryDays). The calendar links these days to the day archive.
 */
class MemberDiaryDays
{
    /** @return list<int> day-of-month numbers (1-31), ascending */
    public function __invoke(?Member $viewer, Member $owner, int $year, int $month): array
    {
        $period = ArchivePeriod::fromYearMonthDay($year, $month);
        if ($period === null) {
            return [];
        }

        $query = Diary::where('member_id', $owner->getKey())
            ->where('created_at', '>=', $period->start)
            ->where('created_at', '<', $period->end);
        DiaryVisibilityScope::apply($query, $viewer, $owner);

        return $query->pluck('created_at')
            ->map(fn ($createdAt) => (int) $createdAt->format('j'))
            ->unique()->sort()->values()->all();
    }
}
