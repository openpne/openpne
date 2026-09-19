<?php

namespace Tests\Feature\Diary\Actions;

use App\Features\Diary\Actions\DeleteDiary;
use App\Features\Diary\Exceptions\DiaryActionException;
use App\Features\Diary\Exceptions\DiaryActionFailure;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    /** The comments go by cascade, which reaches no reaction: both sweeps are the action's, inside its transaction. */
    public function test_purge_sweeps_the_entrys_and_its_comments_reactions_inside_the_transaction(): void
    {
        $diary = Diary::factory()->create();
        $comment = DiaryComment::factory()->create(['diary_id' => $diary->getKey()]);
        $kept = Diary::factory()->create();
        $reactor = Member::factory()->create();
        foreach ([$diary, $comment, $kept] as $target) {
            $target->reactions()->create(['member_id' => $reactor->getKey(), 'emoji' => "\u{1F44D}"]);
        }
        $outside = DB::transactionLevel();
        $sweepLevels = [];
        DB::listen(function ($query) use (&$sweepLevels): void {
            if (preg_match('/^delete from [`"]reactions[`"]/', $query->sql)) {
                $sweepLevels[] = $query->connection->transactionLevel();
            }
        });

        (new DeleteDiary)->purge($diary);

        $this->assertSame([$outside + 1, $outside + 1], $sweepLevels);
        $this->assertDatabaseMissing('reactions', ['reactable_id' => $diary->getKey(), 'reactable_type' => 'diary']);
        $this->assertDatabaseMissing('reactions', ['reactable_id' => $comment->getKey(), 'reactable_type' => 'diaryComment']);
        $this->assertDatabaseHas('reactions', ['reactable_id' => $kept->getKey(), 'reactable_type' => 'diary']);
    }
}
