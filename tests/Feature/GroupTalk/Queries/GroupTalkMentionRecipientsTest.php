<?php

namespace Tests\Feature\GroupTalk\Queries;

use App\Features\GroupTalk\Queries\GroupTalkMentionRecipients;
use App\Models\Group;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ShapesAudience;
use Tests\TestCase;

class GroupTalkMentionRecipientsTest extends TestCase
{
    use RefreshDatabase;
    use ShapesAudience;

    public function test_a_named_member_is_left_out_when_the_author_banned_gone_from_the_group_or_blocked_either_way(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $named = $this->joined($group);
        $muted = $this->joined($group, muted: true);
        $gone = Member::factory()->create();
        $banned = $this->joined($group, $this->banned());
        $blocking = $this->joined($group);
        $this->block($blocking, $author);
        $blocked = $this->joined($group);
        $this->block($author, $blocked);

        $recipients = app(GroupTalkMentionRecipients::class)(
            $group,
            $author,
            $this->keys($named, $muted, $author, $gone, $banned, $blocking, $blocked),
        );

        $this->assertSame($this->keys($named, $muted), $this->idsOf($recipients));
    }

    public function test_no_mentions_asks_the_database_nothing(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        DB::enableQueryLog();

        $this->assertSame([], app(GroupTalkMentionRecipients::class)($group, $author, []));
        $this->assertSame([], DB::getQueryLog());
    }
}
