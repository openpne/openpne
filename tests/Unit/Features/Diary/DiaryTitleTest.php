<?php

namespace Tests\Unit\Features\Diary;

use App\Features\Diary\DiaryTitle;
use App\Models\Diary;
use PHPUnit\Framework\TestCase;

class DiaryTitleTest extends TestCase
{
    public function test_short_title_keeps_its_text_and_appends_the_count(): void
    {
        $this->assertSame('Hello (3)', DiaryTitle::withCount($this->diary('Hello', 3)));
    }

    public function test_missing_count_renders_as_zero(): void
    {
        $diary = new Diary;
        $diary->title = 'No count loaded';

        $this->assertSame('No count loaded (0)', DiaryTitle::withCount($diary));
    }

    public function test_long_ascii_title_is_truncated_to_36_without_an_ellipsis(): void
    {
        $label = DiaryTitle::withCount($this->diary(str_repeat('a', 50), 0));

        $this->assertSame(str_repeat('a', 36).' (0)', $label);
    }

    public function test_full_width_title_is_truncated_by_display_width(): void
    {
        // 20 full-width characters span display width 40; OpenPNE 3 truncates to width 36 = 18 characters.
        $label = DiaryTitle::withCount($this->diary(str_repeat('あ', 20), 1));

        $this->assertSame(str_repeat('あ', 18).' (1)', $label);
    }

    private function diary(string $title, int $count): Diary
    {
        $diary = new Diary;
        $diary->title = $title;
        $diary->comments_count = $count;

        return $diary;
    }
}
