<?php

namespace Tests\Feature\Timeline\Actions;

use App\Features\Timeline\Actions\CreateReply;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Visibility;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CreateReplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_reply_is_a_child_row_inheriting_the_parents_visibility(): void
    {
        [$author, $replier] = Member::factory()->count(2)->create()->all();
        $parent = TimelinePost::factory()->friends()->create(['member_id' => $author->getKey()]);

        $reply = app(CreateReply::class)($replier, $parent, 'nice post');

        $this->assertSame($parent->getKey(), $reply->in_reply_to_id);
        $this->assertSame($replier->getKey(), $reply->member_id);
        $this->assertSame('nice post', $reply->body);
        // The reply inherits the thread's audience so the whole thread is gated as one.
        $this->assertSame(Visibility::Friends, $reply->visibility);
    }

    public function test_mentioned_members_are_read_before_the_reply_row_is_written(): void
    {
        [$author, $replier, $mentioned] = Member::factory()->count(3)->create()->all();
        $parent = TimelinePost::factory()->create(['member_id' => $author->getKey()]);
        $body = '@'.$mentioned->name.' hi';

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        app(CreateReply::class)($replier, $parent, $body, [
            ['member_id' => $mentioned->getKey(), 'offset' => 0, 'length' => mb_strlen('@'.$mentioned->name)],
        ]);

        $members = $this->firstIndex($queries, fn (string $sql): bool => str_contains($sql, 'from "members"') || str_contains($sql, 'from `members`'));
        // The root hold is the first statement that touches the posts table; the insert follows it.
        $root = $this->firstIndex($queries, fn (string $sql): bool => str_contains($sql, 'timeline_posts'));
        $this->assertNotNull($members);
        $this->assertNotNull($root);
        $this->assertStringStartsWith('select', $queries[$root]);
        $this->assertLessThan($root, $members);
    }

    public function test_parent_swept_after_the_lookup_is_a_not_found_rather_than_a_constraint_failure(): void
    {
        [$author, $replier] = Member::factory()->count(2)->create()->all();
        $parent = TimelinePost::factory()->create(['member_id' => $author->getKey()]);
        TimelinePost::whereKey($parent->getKey())->delete();

        try {
            app(CreateReply::class)($replier, $parent, 'too late');
            $this->fail('the reply went through under a swept root');
        } catch (ModelNotFoundException $e) {
            $this->assertSame(TimelinePost::class, $e->getModel());
        }
        $this->assertDatabaseCount('timeline_posts', 0);
    }

    /**
     * @param  list<string>  $queries
     */
    private function firstIndex(array $queries, callable $match): ?int
    {
        foreach ($queries as $i => $sql) {
            if ($match($sql)) {
                return $i;
            }
        }

        return null;
    }
}
