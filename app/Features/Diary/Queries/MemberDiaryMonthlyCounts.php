<?php

namespace App\Features\Diary\Queries;

use App\Features\Diary\ArchivePeriod;
use App\Features\Diary\DiaryVisibilityScope;
use App\Models\Diary;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

/**
 * Per-month counts of an author's viewer-visible diaries: one row per month that has an entry,
 * newest first. The year-month is extracted from the stored `created_at`, so a month's count and
 * that month's {@see ArchivePeriod} list cannot diverge (docs/internals/diary.md, "The archive").
 */
class MemberDiaryMonthlyCounts
{
    /** @return list<array{year: int, month: int, count: int}> */
    public function __invoke(?Member $viewer, Member $owner, string $keyword = ''): array
    {
        // strftime/DATE_FORMAT diverge across the sqlite and MySQL CI lanes (see SearchMembers::monthDayExpr).
        $ym = DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', created_at)"
            : "DATE_FORMAT(created_at, '%Y-%m')";

        $query = Diary::query()
            ->where('member_id', $owner->getKey())
            ->selectRaw("{$ym} as ym, count(*) as total")
            ->groupBy('ym')
            ->orderByDesc('ym');
        DiaryVisibilityScope::apply($query, $viewer, $owner);
        SearchDiaries::applyTerms($query, $keyword);

        return $query->get()->map(function ($row): array {
            [$year, $month] = explode('-', (string) $row->ym);

            return ['year' => (int) $year, 'month' => (int) $month, 'count' => (int) $row->total];
        })->all();
    }
}
