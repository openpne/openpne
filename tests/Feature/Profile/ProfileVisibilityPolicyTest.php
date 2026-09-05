<?php

namespace Tests\Feature\Profile;

use App\Features\Profile\ProfileAccess;
use App\Features\Profile\ProfileVisibilityPolicy;
use App\Models\Member;
use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The base TestCase seeds member_choice so every other test's "web-public profile" means what it
 * says; the shipped members-only default is pinned here by clearing that row.
 */
class ProfileVisibilityPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function policy(?ProfileVisibilityPolicy $policy): void
    {
        if ($policy === null) {
            DB::table('sns_settings')->where('key', SnsSettingKey::ProfileVisibilityPolicy->value)->delete();
            app(SnsSettingService::class)->clearCache();

            return;
        }

        $this->setSnsSetting(SnsSettingKey::ProfileVisibilityPolicy, $policy);
    }

    public function test_the_shipped_default_holds_every_page_at_members_only(): void
    {
        $this->policy(null);
        $open = Member::factory()->create(['profile_visibility' => Visibility::Open]);

        $this->assertFalse(ProfileAccess::isWebPublic($open));
        $this->get("/member/{$open->getKey()}")->assertRedirect('/login');
    }

    public function test_the_guest_gate_is_the_policy_over_the_members_choice(): void
    {
        $open = Member::factory()->create(['profile_visibility' => Visibility::Open]);
        $members = Member::factory()->create(['profile_visibility' => Visibility::Members]);

        foreach ([
            [ProfileVisibilityPolicy::Web, true, true],
            [ProfileVisibilityPolicy::Members, false, false],
            [ProfileVisibilityPolicy::MemberChoice, true, false],
        ] as [$policy, $openReachable, $membersReachable]) {
            $this->policy($policy);

            $this->assertSame($openReachable, ProfileAccess::isWebPublic($open), $policy->value);
            $this->assertSame($membersReachable, ProfileAccess::isWebPublic($members), $policy->value);
            $this->assertSame($openReachable, $this->get("/member/{$open->getKey()}")->status() === 200, $policy->value);
            $this->assertSame($membersReachable, $this->get("/member/{$members->getKey()}")->status() === 200, $policy->value);
        }
    }

    public function test_a_stored_friends_tier_from_an_earlier_upgrade_reads_as_members(): void
    {
        $this->policy(ProfileVisibilityPolicy::MemberChoice);
        $member = Member::factory()->create(['profile_visibility' => Visibility::Friends]);

        $this->assertFalse(ProfileAccess::isWebPublic($member));
        $this->actingAs($member)->get('/member/config?category=publicFlag')
            ->assertOk()
            ->assertSee('<option value="1" selected', false)
            ->assertDontSee('<option value="0" selected', false);
    }

    public function test_a_member_chooses_only_under_the_member_choice_policy(): void
    {
        $member = Member::factory()->create(['profile_visibility' => Visibility::Members]);

        $this->actingAs($member)->post('/member/config/profile-visibility', ['profile_visibility' => (string) Visibility::Open->value])
            ->assertRedirect(route('member.config', ['category' => 'publicFlag']));
        $this->assertSame(Visibility::Open, $member->fresh()->profile_visibility);

        $this->policy(ProfileVisibilityPolicy::Members);

        $this->actingAs($member)->post('/member/config/profile-visibility', ['profile_visibility' => (string) Visibility::Members->value])
            ->assertRedirect(route('member.config'));
        // Dormant, not overwritten: the choice comes back when the policy does.
        $this->assertSame(Visibility::Open, $member->fresh()->profile_visibility);
        $this->assertFalse(ProfileAccess::isWebPublic($member->fresh()));

        $this->policy(ProfileVisibilityPolicy::MemberChoice);
        $this->assertTrue(ProfileAccess::isWebPublic($member->fresh()));
    }

    public function test_only_the_two_openpne3_tiers_are_accepted(): void
    {
        $member = Member::factory()->create();

        foreach ([Visibility::Friends, Visibility::Private] as $tier) {
            $this->actingAs($member)->post('/member/config/profile-visibility', ['profile_visibility' => (string) $tier->value])
                ->assertSessionHasErrors('profile_visibility');
        }
        $this->assertSame(Visibility::Members, $member->fresh()->profile_visibility);
    }

    public function test_the_classic_privacy_category_offers_the_form_only_under_member_choice(): void
    {
        $member = Member::factory()->create();

        // No birthday item, so the category exists for this form alone.
        $link = 'href="'.route('member.config', ['category' => 'publicFlag']).'"';
        $this->actingAs($member)->get('/member/config')->assertOk()->assertSee($link, false);
        $this->actingAs($member)->get('/member/config?category=publicFlag')
            ->assertOk()
            ->assertSee('id="profileVisibilityForm"', false)
            ->assertDontSee('id="publicFlagForm"', false);

        $this->policy(ProfileVisibilityPolicy::Members);

        $this->actingAs($member)->get('/member/config')->assertOk()->assertDontSee($link, false);
        $this->actingAs($member)->get('/member/config?category=publicFlag')
            ->assertOk()
            ->assertDontSee('id="profileVisibilityForm"', false);
    }

    public function test_the_modern_settings_page_carries_the_field_only_under_member_choice(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $member = Member::factory()->create(['profile_visibility' => Visibility::Open]);

        $this->actingAs($member)->get('/member/config')
            ->assertInertia(fn ($page) => $page
                ->where('form.profileVisibility.value', '0')
                ->has('form.profileVisibility.options', 2));

        $this->policy(ProfileVisibilityPolicy::Web);

        $this->actingAs($member)->get('/member/config')
            ->assertInertia(fn ($page) => $page->missing('form.profileVisibility'));
    }
}
