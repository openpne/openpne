<?php

namespace Tests\Feature\Diary\Actions;

use App\Features\Diary\Actions\DeleteComment;
use App\Features\Diary\Exceptions\DiaryActionException;
use App\Features\Diary\Exceptions\DiaryActionFailure;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
