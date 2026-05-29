<?php

namespace Tests\Feature\Diary\Actions;

use App\Features\Diary\Actions\DeleteDiary;
use App\Features\Diary\Exceptions\DiaryActionException;
use App\Features\Diary\Exceptions\DiaryActionFailure;
use App\Models\Diary;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeleteDiaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_delete_diary(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);

        (new DeleteDiary)($owner, $diary);

        $this->assertDatabaseMissing('diaries', ['id' => $diary->getKey()]);
    }

    public function test_non_owner_throws_not_author_and_leaves_db_unchanged(): void
    {
        [$owner, $other] = Member::factory()->count(2)->create()->all();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);

        try {
            (new DeleteDiary)($other, $diary);
            $this->fail('Expected DiaryActionException');
        } catch (DiaryActionException $e) {
            $this->assertSame(DiaryActionFailure::NotAuthor, $e->reason);
        }

        $this->assertDatabaseHas('diaries', ['id' => $diary->getKey()]);
    }
}
