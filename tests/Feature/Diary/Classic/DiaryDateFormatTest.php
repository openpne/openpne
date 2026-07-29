<?php

namespace Tests\Feature\Diary\Classic;

use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\Member;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks the OpenPNE 3 op_format_date display on the diary show page: the entry stacks its
 * created-at over three lines in the dt column (XDateTimeJaBr + nl2br), the comment list keeps
 * the one-line form, both in the kanji pattern under the Japanese locale.
 */
class DiaryDateFormatTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_stacks_the_japanese_datetime_in_the_dt_column(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create([
            'member_id' => $owner->getKey(),
            'created_at' => CarbonImmutable::create(2026, 6, 4, 13, 44),
        ]);

        $this->actingAs($owner)
            ->withSession(['locale' => 'ja'])
            ->get("/diary/{$diary->getKey()}")
            ->assertOk()
            ->assertSee('<dt>2026年<br />06月04日<br />13:44</dt>', false);
    }

    public function test_show_keeps_one_line_for_a_locale_without_the_stacked_pattern(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create([
            'member_id' => $owner->getKey(),
            'created_at' => CarbonImmutable::create(2026, 6, 4, 13, 44),
        ]);

        // OpenPNE 3 falls back to the same one-line datetime outside ja_JP, so nl2br adds nothing.
        $this->actingAs($owner)
            ->withSession(['locale' => 'en'])
            ->get("/diary/{$diary->getKey()}")
            ->assertOk()
            ->assertSee('<dt>June 4, 2026 1:44 PM</dt>', false);
    }

    public function test_show_stacks_the_japanese_comment_datetime(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);
        DiaryComment::factory()->for($diary)->create([
            'number' => 1,
            'created_at' => CarbonImmutable::create(2026, 6, 4, 9, 5),
        ]);

        // XDateTimeJaBr, as the entry's own dt: the comment timestamp stacks too.
        $this->actingAs($owner)
            ->withSession(['locale' => 'ja'])
            ->get("/diary/{$diary->getKey()}")
            ->assertOk()
            ->assertSee('<dt>2026年<br />06月04日<br />09:05</dt>', false);
    }
}
