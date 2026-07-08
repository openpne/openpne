<?php

declare(strict_types=1);

namespace Tests\Feature\Community\Listeners;

use App\Features\Community\Actions\JoinCommunity;
use App\Features\Community\CommunityRole;
use App\Features\Community\Events\CommunityJoined;
use App\Features\Community\JoinPolicy;
use App\Listeners\Community\NotifyCommunityJoined;
use App\Mail\Template\MailTemplate;
use App\Models\Community;
use App\Models\Member;
use App\Notifications\Community\CommunityJoinedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotifyCommunityJoinedTest extends TestCase
{
    use RefreshDatabase;

    public function test_notifies_every_admin_but_not_the_joiner(): void
    {
        Notification::fake();
        [$admin1, $admin2, $sub, $plain, $joiner] = Member::factory()->count(5)->create()->all();
        $community = Community::factory()->create();
        $this->addMember($community, $admin1, CommunityRole::Admin);
        $this->addMember($community, $admin2, CommunityRole::Admin);
        $this->addMember($community, $sub, CommunityRole::SubAdmin);
        $this->addMember($community, $plain, CommunityRole::Member);

        $this->handle($community, $joiner);

        foreach ([$admin1, $admin2] as $admin) {
            Notification::assertSentTo(
                $admin,
                CommunityJoinedNotification::class,
                fn (CommunityJoinedNotification $n, array $channels) => $n->newMember->is($joiner)
                    && $channels === ['mail', 'database'],
            );
        }
        // Only admins are recipients — sub-admins and members are not.
        Notification::assertNotSentTo([$sub, $plain, $joiner], CommunityJoinedNotification::class);
    }

    public function test_sends_nothing_when_the_community_opted_out(): void
    {
        Notification::fake();
        [$admin, $joiner] = Member::factory()->count(2)->create()->all();
        $community = Community::factory()->create(['is_join_notification_enabled' => false]);
        $this->addMember($community, $admin, CommunityRole::Admin);

        $this->handle($community, $joiner);

        Notification::assertNothingSent();
    }

    public function test_skips_a_banned_admin(): void
    {
        Notification::fake();
        [$admin, $joiner] = Member::factory()->count(2)->create()->all();
        $admin->forceFill(['is_login_rejected' => true])->save();
        $community = Community::factory()->create();
        $this->addMember($community, $admin, CommunityRole::Admin);

        $this->handle($community, $joiner);

        Notification::assertNotSentTo($admin, CommunityJoinedNotification::class);
    }

    public function test_skips_an_admin_blocked_against_the_joiner(): void
    {
        Notification::fake();
        [$admin, $joiner] = Member::factory()->count(2)->create()->all();
        DB::table('member_blocks')->insert(['blocker_id' => $admin->getKey(), 'blocked_id' => $joiner->getKey()]);
        $community = Community::factory()->create();
        $this->addMember($community, $admin, CommunityRole::Admin);

        $this->handle($community, $joiner);

        Notification::assertNotSentTo($admin, CommunityJoinedNotification::class);
    }

    public function test_a_disabled_template_drops_mail_but_keeps_the_database_record(): void
    {
        Notification::fake();
        [$admin, $joiner] = Member::factory()->count(2)->create()->all();
        $community = Community::factory()->create();
        $this->addMember($community, $admin, CommunityRole::Admin);
        // community-join is configurable; an admin turned it off globally.
        DB::table('mail_templates')->insert(['key' => MailTemplate::CommunityJoinNotice->value, 'is_enabled' => false]);

        $this->handle($community, $joiner);

        Notification::assertSentTo(
            $admin,
            CommunityJoinedNotification::class,
            fn (CommunityJoinedNotification $n, array $channels) => $channels === ['database'],
        );
    }

    public function test_joining_an_open_community_notifies_through_auto_discovery(): void
    {
        Notification::fake();
        [$admin, $joiner] = Member::factory()->count(2)->create()->all();
        $community = Community::factory()->create(['register_policy' => JoinPolicy::Open]);
        $this->addMember($community, $admin, CommunityRole::Admin);

        app(JoinCommunity::class)($joiner, $community);

        Notification::assertSentTo($admin, CommunityJoinedNotification::class);
    }

    private function handle(Community $community, Member $joiner): void
    {
        app(NotifyCommunityJoined::class)->handle(new CommunityJoined($community, $joiner));
    }

    private function addMember(Community $community, Member $member, CommunityRole $role): void
    {
        $community->members()->create(['member_id' => $member->getKey(), 'role' => $role]);
    }
}
