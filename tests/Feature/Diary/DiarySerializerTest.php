<?php

namespace Tests\Feature\Diary;

use App\Features\Diary\Serializers\DiarySerializer;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiarySerializerTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_loads_the_comment_count_when_not_eager_loaded(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);
        DiaryComment::factory()->for($diary)->create(['number' => 1]);
        DiaryComment::factory()->for($diary)->create(['number' => 2]);

        // A route-bound diary carries no withCount('comments'); summary() must still report the count.
        $fresh = Diary::findOrFail($diary->getKey());

        $this->assertSame(2, DiarySerializer::summary($fresh)['commentCount']);
    }
}
