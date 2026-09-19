<?php

namespace Tests\Feature\Diary\Actions;

use App\Features\Diary\Actions\DeleteComment;
use App\Features\Diary\Exceptions\DiaryActionException;
use App\Features\Diary\Exceptions\DiaryActionFailure;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeleteCommentTest extends TestCase
{
    use RefreshDatabase;

    public function test_comment_author_can_delete_own_comment(): void
    {
        $diary = Diary::factory()->create();
        $author = Member::factory()->create();
        $comment = DiaryComment::factory()->create([
            'diary_id' => $diary->getKey(), 'member_id' => $author->getKey(),
        ]);

        (new DeleteComment)($author, $comment);

        $this->assertDatabaseMissing('diary_comments', ['id' => $comment->getKey()]);
    }

    public function test_diary_author_can_delete_anyones_comment(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);
        $commenter = Member::factory()->create();
        $comment = DiaryComment::factory()->create([
            'diary_id' => $diary->getKey(), 'member_id' => $commenter->getKey(),
        ]);

        (new DeleteComment)($owner, $comment);

        $this->assertDatabaseMissing('diary_comments', ['id' => $comment->getKey()]);
    }

    public function test_unrelated_member_cannot_delete_and_leaves_db_unchanged(): void
    {
        $diary = Diary::factory()->create();
        $commenter = Member::factory()->create();
        $stranger = Member::factory()->create();
        $comment = DiaryComment::factory()->create([
            'diary_id' => $diary->getKey(), 'member_id' => $commenter->getKey(),
        ]);

        try {
            (new DeleteComment)($stranger, $comment);
            $this->fail('Expected DiaryActionException');
        } catch (DiaryActionException $e) {
            $this->assertSame(DiaryActionFailure::NotAuthor, $e->reason);
        }

        $this->assertDatabaseHas('diary_comments', ['id' => $comment->getKey()]);
    }

    public function test_purge_sweeps_the_comments_reactions_and_leaves_the_entrys(): void
    {
        $diary = Diary::factory()->create();
        $comment = DiaryComment::factory()->create(['diary_id' => $diary->getKey()]);
        $reactor = Member::factory()->create();
        $diary->reactions()->create(['member_id' => $reactor->getKey(), 'emoji' => "\u{1F44D}"]);
        $comment->reactions()->create(['member_id' => $reactor->getKey(), 'emoji' => "\u{1F44D}"]);
        $outside = DB::transactionLevel();
        $sweepLevels = [];
        DB::listen(function ($query) use (&$sweepLevels): void {
            if (preg_match('/^delete from [`"]reactions[`"]/', $query->sql)) {
                $sweepLevels[] = $query->connection->transactionLevel();
            }
        });

        (new DeleteComment)->purge($comment);

        $this->assertSame([$outside + 1], $sweepLevels);
        $this->assertDatabaseMissing('reactions', ['reactable_type' => 'diaryComment']);
        $this->assertDatabaseHas('reactions', ['reactable_id' => $diary->getKey(), 'reactable_type' => 'diary']);
    }
}
