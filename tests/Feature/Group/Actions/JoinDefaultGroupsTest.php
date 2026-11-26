<?php

namespace Tests\Feature\Group\Actions;

use App\Features\AiAccount\Actions\CreateAiAccount;
use App\Features\Group\GroupRole;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use App\Models\RegistrationToken;
use App\Notifications\Group\GroupJoinedNotification;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class JoinDefaultGroupsTest extends TestCase
{
    use RefreshDatabase;

    private function register(string $email = 'newcomer@example.com'): Member
    {
        $raw = Str::random(40);
        RegistrationToken::create(['email' => $email, 'token' => hash('sha256', $raw), 'created_at' => now()]);

        $this->post("/register/{$raw}", ['name' => 'Newcomer', 'password' => 'sufficiently-long-pw', 'password_confirmation' => 'sufficiently-long-pw'])
            ->assertRedirect('/');

        return Member::where('email', $email)->firstOrFail();
    }

    public function test_registration_joins_every_default_group_as_a_plain_member(): void
    {
        $open = Group::factory()->create(['is_default' => true]);
        // OpenPNE 3 joined a default community whatever its policy, never as an applicant.
        $approval = Group::factory()->approval()->create(['is_default' => true]);
        $other = Group::factory()->create();

        $member = $this->register();

        foreach ([$open, $approval] as $group) {
            $this->assertSame(
                GroupRole::Member,
                GroupMember::where('group_id', $group->getKey())->where('member_id', $member->getKey())->value('role'),
            );
        }
        $this->assertDatabaseMissing('group_members', ['group_id' => $other->getKey(), 'member_id' => $member->getKey()]);
        $this->assertDatabaseMissing('group_join_requests', ['member_id' => $member->getKey()]);
    }

    public function test_the_group_admins_are_not_notified(): void
    {
        Notification::fake();
        $group = Group::factory()->create(['is_default' => true, 'is_join_notification_enabled' => true]);
        $admin = Member::factory()->create();
        GroupMember::factory()->admin()->create(['group_id' => $group->getKey(), 'member_id' => $admin->getKey()]);

        $member = $this->register();

        $this->assertDatabaseHas('group_members', ['group_id' => $group->getKey(), 'member_id' => $member->getKey()]);
        Notification::assertNotSentTo($admin, GroupJoinedNotification::class);
    }

    public function test_an_ai_account_is_not_a_registration(): void
    {
        $this->setSnsSetting(SnsSettingKey::AiAccountsEnabled, true);
        $group = Group::factory()->create(['is_default' => true]);

        $ai = app(CreateAiAccount::class)(Member::factory()->create(), 'Helper');

        $this->assertDatabaseMissing('group_members', ['group_id' => $group->getKey(), 'member_id' => $ai->getKey()]);
    }
}
