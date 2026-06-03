<?php

namespace Tests\Feature\Diary\Actions;

use App\Features\Diary\Actions\CreateComment;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateCommentTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_comment_with_author_and_first_number(): void
    {
        $diary = Diary::factory()->create();
        $author = Member::factory()->create();

        $comment = (new CreateComment)($author, $diary, 'Nice entry');

        $this->assertDatabaseHas('diary_comments', [
            'id' => $comment->getKey(),
            'diary_id' => $diary->getKey(),
            'member_id' => $author->getKey(),
            'number' => 1,
            'body' => 'Nice entry',
        ]);
    }

    public function test_numbers_increment_per_diary(): void
    {
        $diary = Diary::factory()->create();
        $other = Diary::factory()->create();
        $author = Member::factory()->create();

        $first = (new CreateComment)($author, $diary, 'one');
        $second = (new CreateComment)($author, $diary, 'two');
        // A different diary starts its own sequence at 1.
        $elsewhere = (new CreateComment)($author, $other, 'elsewhere');

        $this->assertSame(1, $first->number);
        $this->assertSame(2, $second->number);
        $this->assertSame(1, $elsewhere->number);
    }

    public function test_duplicate_numbers_are_permitted_for_upgrade_fidelity(): void
    {
        // OpenPNE 3's (diary_id, number) index is non-unique and its number is a racy max+1,
        // so legacy data can carry duplicates. The table must import them, not reject them —
        // flipping this to a unique constraint would break the OpenPNE 3 upgrade.
        $diary = Diary::factory()->create();
        DiaryComment::factory()->create(['diary_id' => $diary->getKey(), 'number' => 1]);
        DiaryComment::factory()->create(['diary_id' => $diary->getKey(), 'number' => 1]);

        $this->assertDatabaseCount('diary_comments', 2);
    }

    public function test_accepts_long_body_beyond_varchar_limit(): void
    {
        $diary = Diary::factory()->create();
        $author = Member::factory()->create();
        $longBody = str_repeat('あ', 500);

        $comment = (new CreateComment)($author, $diary, $longBody);

        $this->assertSame($longBody, $comment->fresh()->body);
    }
}
