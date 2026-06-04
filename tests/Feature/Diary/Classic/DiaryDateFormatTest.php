<?php

namespace Tests\Feature\Diary\Classic;

use App\Models\Diary;
use App\Models\Member;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks the OpenPNE 3 op_format_date(XDateTimeJa) display: the Japanese locale renders the
 * created-at timestamp as the kanji date pattern on the diary show page.
 */
class DiaryDateFormatTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_renders_the_japanese_datetime(): void
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
            ->assertSee('2026年06月04日 13:44');
    }
}
