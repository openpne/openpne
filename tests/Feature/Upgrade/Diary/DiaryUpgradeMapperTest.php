<?php

namespace Tests\Feature\Upgrade\Diary;

use App\Features\Diary\Visibility;
use App\Models\Diary;
use App\Models\Member;
use App\Upgrade\Diary\DiaryUpgradeMapper;
use App\Upgrade\Diary\LegacyDiary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiaryUpgradeMapperTest extends TestCase
{
    use RefreshDatabase;

    private function legacyRow(Member $owner, array $overrides = []): LegacyDiary
    {
        return LegacyDiary::fromRow(array_merge([
            'id' => 4321,
            'member_id' => $owner->getKey(),
            'title' => 'Legacy title',
            'body' => 'Legacy body',
            'public_flag' => 1,
            'is_open' => 0,
            'has_images' => 0,
            'created_at' => '2018-03-04 12:34:56',
            'updated_at' => '2019-06-07 01:02:03',
        ], $overrides));
    }

    public function test_preserves_id_owner_and_timestamps(): void
    {
        $owner = Member::factory()->create();

        $diary = (new DiaryUpgradeMapper)->store($this->legacyRow($owner));

        // id is preserved so deferred comment/image FKs (diary_comment.diary_id) still line up.
        $this->assertSame(4321, $diary->getKey());
        $this->assertTrue($diary->member->is($owner));
        $this->assertDatabaseHas('diaries', [
            'id' => 4321,
            'member_id' => $owner->getKey(),
            // OpenPNE 3 timestamps, not the upgrade run's clock.
            'created_at' => '2018-03-04 12:34:56',
            'updated_at' => '2019-06-07 01:02:03',
        ]);
    }

    public function test_preserves_long_text_title_and_body(): void
    {
        // OpenPNE 3 diary.title/body are TEXT with no validator limit. A >255-char value
        // must round-trip through the upgrade path (the column is TEXT, not VARCHAR(255)).
        $owner = Member::factory()->create();
        $longTitle = str_repeat('あ', 500);
        $longBody = str_repeat('本文', 5000);

        (new DiaryUpgradeMapper)->store($this->legacyRow($owner, [
            'title' => $longTitle,
            'body' => $longBody,
        ]));

        $stored = Diary::findOrFail(4321);
        $this->assertSame($longTitle, $stored->title);
        $this->assertSame($longBody, $stored->body);
    }

    public function test_persists_mapped_visibility_and_reads_back_as_enum(): void
    {
        $owner = Member::factory()->create();

        (new DiaryUpgradeMapper)->store($this->legacyRow($owner, [
            'public_flag' => 1,
            'is_open' => 1,
        ]));

        $this->assertDatabaseHas('diaries', ['id' => 4321, 'visibility' => Visibility::Open->value]);
        $this->assertSame(Visibility::Open, Diary::findOrFail(4321)->visibility);
    }

    public function test_target_attributes_drop_accepted_gap_columns(): void
    {
        // has_images is an accepted gap (image delivery is Phase 2); diary_comment is PR D2.
        // The target schema carries neither, so the mapper must not emit them.
        $owner = Member::factory()->create();

        $attributes = (new DiaryUpgradeMapper)->toAttributes($this->legacyRow($owner));

        $this->assertSame(
            ['id', 'member_id', 'title', 'body', 'visibility', 'created_at', 'updated_at'],
            array_keys($attributes),
        );
    }
}
