<?php

namespace Tests\Feature\Timeline\Queries;

use App\Features\Timeline\Queries\TimelineMentionRecipients;
use App\Models\Member;
use App\Models\TimelinePost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ShapesAudience;
use Tests\TestCase;

class TimelineMentionRecipientsTest extends TestCase
{
    use RefreshDatabase;
    use ShapesAudience;

    public function test_a_named_member_is_left_out_when_the_author_banned_or_blocked_either_way(): void
    {
        $author = Member::factory()->create();
        $post = TimelinePost::factory()->create(['member_id' => $author->getKey()]);
        $named = Member::factory()->create();
        $banned = $this->banned();
        $blocking = Member::factory()->create();
        $this->block($blocking, $author);
        $blocked = Member::factory()->create();
        $this->block($author, $blocked);

        $recipients = app(TimelineMentionRecipients::class)($post, $author, $this->keys($named, $author, $banned, $blocking, $blocked));

        $this->assertSame($this->keys($named), $this->idsOf($recipients));
    }

    public function test_the_thread_root_decides_who_may_be_named_in_a_reply(): void
    {
        $owner = Member::factory()->create();
        $root = TimelinePost::factory()->friends()->create(['member_id' => $owner->getKey()]);
        $author = Member::factory()->create();
        $this->befriend($author, $owner);
        $reply = TimelinePost::factory()->replyTo($root)->create(['member_id' => $author->getKey()]);
        $friend = Member::factory()->create();
        $this->befriend($friend, $owner);
        $stranger = Member::factory()->create();

        $recipients = app(TimelineMentionRecipients::class)($reply, $author, $this->keys($owner, $friend, $stranger));

        $this->assertSame($this->keys($owner, $friend), $this->idsOf($recipients));
    }

    public function test_no_mentions_asks_the_database_nothing(): void
    {
        $post = TimelinePost::factory()->create();
        $author = $post->member;
        DB::enableQueryLog();

        $this->assertSame([], app(TimelineMentionRecipients::class)($post, $author, []));
        $this->assertSame([], DB::getQueryLog());
    }
}
