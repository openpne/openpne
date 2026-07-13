<?php

namespace Tests\Feature\Diary\Classic;

use App\Models\Diary;
use App\Models\DiaryImage;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The rich Modern rows eager-load a diary's first image inside the Modern closure only. Classic must
 * stay at zero added cost: it never loads the firstImage one-of-many relation (nor its follow-up
 * files query), and its query count does not grow with the number of image-bearing entries.
 */
class DiaryRichRowCostTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['openpne.surface_mode' => 'classic_default']);
    }

    /** @return list<string> */
    private function queriesFor(Member $viewer, string $uri): array
    {
        DB::enableQueryLog();
        $this->actingAs($viewer)->get($uri)->assertOk();
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        return $queries;
    }

    private function attachImage(Diary $diary): void
    {
        DiaryImage::factory()->create(['diary_id' => $diary->getKey(), 'number' => 1]);
    }

    public function test_classic_feed_never_loads_the_modern_first_image_relation(): void
    {
        $viewer = Member::factory()->create();
        foreach (Member::factory()->count(3)->create() as $author) {
            $this->attachImage(Diary::factory()->create(['member_id' => $author->getKey(), 'visibility' => Visibility::Members]));
        }

        foreach ($this->queriesFor($viewer, '/diary/list') as $query) {
            // loadMissing('firstImage.file') aliases its one-of-many subquery "firstImage";
            // the withCount images subquery aliases "images_count", never this. Its absence proves
            // Classic ran no firstImage eager load.
            $this->assertStringNotContainsString('firstImage', $query);
        }
    }

    public function test_classic_archive_never_loads_the_modern_first_image_relation(): void
    {
        $owner = Member::factory()->create();
        foreach (range(1, 3) as $i) {
            $this->attachImage(Diary::factory()->create(['member_id' => $owner->getKey(), 'visibility' => Visibility::Members]));
        }

        foreach ($this->queriesFor($owner, "/diary/listMember/{$owner->getKey()}") as $query) {
            $this->assertStringNotContainsString('firstImage', $query);
        }
    }
}
