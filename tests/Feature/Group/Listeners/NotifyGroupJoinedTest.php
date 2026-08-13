<?php

declare(strict_types=1);

namespace Tests\Feature\Group\Listeners;

use App\Features\Group\Actions\JoinGroup;
use App\Features\Group\Events\GroupJoined;
use App\Features\Group\GroupRole;
use App\Features\Group\JoinPolicy;
use App\Listeners\Group\NotifyGroupJoined;
use App\Mail\Template\MailTemplate;
use App\Models\Group;
use App\Models\Member;
use App\Notifications\Group\GroupJoinedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotifyGroupJoinedTest extends TestCase
{
    use RefreshDatabase;

    public function test_notifies_every_admin_but_not_the_joiner(): void
    {
        Notification::fake();
        [$admin1, $admin2, $sub, $plain, $joiner] = Member::factory()->count(5)->create()->all();
        $group = Group::factory()->create();
        $this->addMember($group, $admin1, GroupRole::Admin);
        $this->addMember($group, $admin2, GroupRole::Admin);
        $this->addMember($group, $sub, GroupRole::SubAdmin);
        $this->addMember($group, $plain, GroupRole::Member);

        $this->handle($group, $joiner);

        foreach ([$admin1, $admin2] as $admin) {
            Notification::assertSentTo(
                $admin,
                GroupJoinedNotification::class,
                fn (GroupJoinedNotification $n, array $channels) => $n->newMember->is($joiner)
                    && $channels === ['mail', 'database'],
            );
        }
        // Only admins are recipients — sub-admins and members are not.
        Notification::assertNotSentTo([$sub, $plain, $joiner], GroupJoinedNotification::class);
    }

    public function test_sends_nothing_when_the_community_opted_out(): void
    {
        Notification::fake();
        [$admin, $joiner] = Member::factory()->count(2)->create()->all();
        $group = Group::factory()->create(['is_join_notification_enabled' => false]);
        $this->addMember($group, $admin, GroupRole::Admin);

        $this->handle($group, $joiner);

        Notification::assertNothingSent();
    }

    public function test_skips_a_banned_admin(): void
    {
        Notification::fake();
        [$admin, $joiner] = Member::factory()->count(2)->create()->all();
        $admin->forceFill(['is_login_rejected' => true])->save();
        $group = Group::factory()->create();
        $this->addMember($group, $admin, GroupRole::Admin);

        $this->handle($group, $joiner);

        Notification::assertNotSentTo($admin, GroupJoinedNotification::class);
    }

    public function test_skips_an_admin_blocked_against_the_joiner(): void
    {
        Notification::fake();
        [$admin, $joiner] = Member::factory()->count(2)->create()->all();
        DB::table('member_blocks')->insert(['blocker_id' => $admin->getKey(), 'blocked_id' => $joiner->getKey()]);
        $group = Group::factory()->create();
        $this->addMember($group, $admin, GroupRole::Admin);

        $this->handle($group, $joiner);

        Notification::assertNotSentTo($admin, GroupJoinedNotification::class);
    }

    public function test_a_disabled_template_drops_mail_but_keeps_the_database_record(): void
    {
        Notification::fake();
        [$admin, $joiner] = Member::factory()->count(2)->create()->all();
        $group = Group::factory()->create();
        $this->addMember($group, $admin, GroupRole::Admin);
        // group-join is configurable; an admin turned it off globally.
        DB::table('mail_templates')->insert(['key' => MailTemplate::GroupJoinNotice->value, 'is_enabled' => false]);

        $this->handle($group, $joiner);

        Notification::assertSentTo(
            $admin,
            GroupJoinedNotification::class,
            fn (GroupJoinedNotification $n, array $channels) => $channels === ['database'],
        );
    }

    public function test_joining_an_open_community_notifies_through_auto_discovery(): void
    {
        Notification::fake();
        [$admin, $joiner] = Member::factory()->count(2)->create()->all();
        $group = Group::factory()->create(['register_policy' => JoinPolicy::Open]);
        $this->addMember($group, $admin, GroupRole::Admin);

        app(JoinGroup::class)($joiner, $group);

        Notification::assertSentTo($admin, GroupJoinedNotification::class);
    }

    private function handle(Group $group, Member $joiner): void
    {
        app(NotifyGroupJoined::class)->handle(new GroupJoined($group, $joiner));
    }

    private function addMember(Group $group, Member $member, GroupRole $role): void
    {
        $group->members()->create(['member_id' => $member->getKey(), 'role' => $role]);
    }
}
