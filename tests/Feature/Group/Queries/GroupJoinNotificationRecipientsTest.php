<?php

namespace Tests\Feature\Group\Queries;

use App\Features\Group\GroupRole;
use App\Features\Group\Queries\GroupJoinNotificationRecipients;
use App\Models\Group;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ShapesAudience;
use Tests\TestCase;

class GroupJoinNotificationRecipientsTest extends TestCase
{
    use RefreshDatabase;
    use ShapesAudience;

    public function test_only_the_other_admins_hear_of_a_join_and_not_a_banned_or_blocked_one(): void
    {
        $group = Group::factory()->create();
        $joiner = $this->joined($group, role: GroupRole::Admin);
        $admin = $this->joined($group, role: GroupRole::Admin);
        $this->joined($group, role: GroupRole::SubAdmin);
        $this->joined($group);
        $this->joined($group, $this->banned(), GroupRole::Admin);
        $blocking = $this->joined($group, role: GroupRole::Admin);
        $this->block($blocking, $joiner);
        $blocked = $this->joined($group, role: GroupRole::Admin);
        $this->block($joiner, $blocked);

        $recipients = app(GroupJoinNotificationRecipients::class)($group, $joiner);

        $this->assertSame($this->keys($admin), $this->idsOf($recipients));
    }

    public function test_a_group_that_switched_the_notice_off_tells_nobody(): void
    {
        $group = Group::factory()->create(['is_join_notification_enabled' => false]);
        $this->joined($group, role: GroupRole::Admin);
        $joiner = $this->joined($group);

        $this->assertSame([], app(GroupJoinNotificationRecipients::class)($group, $joiner));
    }
}
