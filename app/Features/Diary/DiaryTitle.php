<?php

namespace App\Features\Diary;

use App\Models\Diary;

/**
 * OpenPNE 3 op_diary_get_title_and_count: the list/feed label for a diary — its title,
 * truncated to display width 36 (full-width characters count as two, no ellipsis), then the
 * comment count as " (N)". Callers eager-load the count via withCount('comments'); a missing
 * count renders as 0 rather than triggering a per-row query.
 */
final class DiaryTitle
{
    private const WIDTH = 36;

    public static function withCount(Diary $diary): string
    {
        return self::truncate($diary->title).' ('.($diary->comments_count ?? 0).')';
    }

    /** OpenPNE 3 op_diary_url_for_show: the list link carries the comment count, so a new comment makes it an unvisited link again. */
    public static function showUrl(Diary $diary): string
    {
        $count = $diary->comments_count ?? 0;

        return route('diary.show', $count > 0 ? [$diary, 'comment_count' => $count] : $diary);
    }

    private static function truncate(string $title): string
    {
        return mb_strimwidth($title, 0, self::WIDTH, '');
    }
}
