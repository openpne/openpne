<?php

namespace App\View\Components\Gadget;

use App\Features\Diary\DiaryTitle;
use App\Models\Diary;
use App\Support\LocalizedDate;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

/**
 * Shared base for the OpenPNE 3 diary list gadgets: maps a diary collection into the rows the
 * shared _diary-article-rows partial renders. Each concrete kind injects its own query and picks the
 * subject; the member and both counts are eager-loaded by the queries, so mapping stays query-free.
 */
abstract class DiaryListBox extends Component
{
    /** @var list<array{date: string, url: string, title: string, author: string, hasImages: bool}> */
    public array $entries = [];

    /** @param array<string, mixed> $config */
    protected static function limit(array $config): int
    {
        return max(1, (int) ($config['max'] ?? 5));
    }

    /**
     * @param  Collection<int, Diary>  $diaries
     * @return list<array{date: string, url: string, title: string, author: string, hasImages: bool}>
     */
    protected static function toEntries(Collection $diaries): array
    {
        $locale = app()->getLocale();

        return $diaries->map(fn (Diary $diary): array => [
            'date' => LocalizedDate::monthDay($diary->created_at, $locale),
            'url' => route('diary.show', $diary),
            'title' => DiaryTitle::withCount($diary),
            'author' => $diary->member->name,
            'hasImages' => ($diary->images_count ?? 0) > 0,
        ])->all();
    }
}
