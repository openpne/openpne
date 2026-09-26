<?php

namespace Tests\Feature\Group\Queries;

use App\Features\Group\Queries\GroupNewPostRecipients;
use App\Models\Group;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ShapesAudience;
use Tests\TestCase;

class GroupNewPostRecipientsTest extends TestCase
{
    use RefreshDatabase;
    use ShapesAudience;

    public function test_the_viewers_are_the_other_members_who_are_neither_banned_nor_blocked_either_way(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $member = $this->joined($group);
        $this->joined($group, $this->banned());
        $blocking = $this->joined($group);
        $this->block($blocking, $author);
        $blocked = $this->joined($group);
        $this->block($author, $blocked);
        Member::factory()->create();

        $viewers = app(GroupNewPostRecipients::class)->viewers($group, $author)->pluck('id')->sort()->values()->all();

        $this->assertSame($this->keys($member), $viewers);
    }
}
