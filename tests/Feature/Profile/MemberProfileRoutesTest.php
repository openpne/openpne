<?php

namespace Tests\Feature\Profile;

use App\Models\Member;
use App\Models\MemberProfile;
use App\Models\Profile;
use App\Support\PreferenceKey;
use App\Support\SnsSettingKey;
use App\Support\Visibility;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class MemberProfileRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_classic_renders_the_member_profile_with_visible_values(): void
    {
        $owner = Member::factory()->create(['name' => 'Owner']);
        $viewer = Member::factory()->create();
        $this->fieldFor($owner, Visibility::Members, 'a-members-value');

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertSee('Owner')
            ->assertSee('a-members-value')
            ->assertSee('page_member_profile'); // OpenPNE 3 body id from the route parity
    }

    public function test_modern_renders_the_inertia_component(): void
    {
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        $this->fieldFor($owner, Visibility::Members, 'v');

        $this->actingAs($viewer)->get("/m/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('member/show')
                ->where('profile.owner.id', $owner->getKey())
                ->has('profile.fields', 1)
            );
    }

    public function test_private_value_is_hidden_from_a_non_friend(): void
    {
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        $this->fieldFor($owner, Visibility::Private, 'secret-bio');

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertDontSee('secret-bio');
    }

    public function test_blocked_viewer_gets_404(): void
    {
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        $this->fieldFor($owner, Visibility::Members, 'v');
        DB::table('member_blocks')->insert(['blocker_id' => $owner->getKey(), 'blocked_id' => $viewer->getKey()]);

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")->assertNotFound();
    }

    public function test_guest_on_a_non_web_public_profile_is_redirected_to_login(): void
    {
        $owner = Member::factory()->create(); // default profile_visibility = Members

        $this->get("/member/{$owner->getKey()}")->assertRedirect('/login');
    }

    public function test_guest_can_view_a_web_public_profile(): void
    {
        $owner = Member::factory()->create(['name' => 'Public Owner', 'profile_visibility' => Visibility::Open]);
        $this->webField($owner, 'public-value');

        $this->get("/member/{$owner->getKey()}")->assertOk()->assertSee('public-value');
    }

    public function test_guest_does_not_see_non_web_public_fields_on_a_web_public_profile(): void
    {
        $owner = Member::factory()->create(['profile_visibility' => Visibility::Open]);
        $this->webField($owner, 'shown');
        $this->fieldFor($owner, Visibility::Members, 'hidden-value');

        $this->get("/member/{$owner->getKey()}")->assertOk()->assertSee('shown')->assertDontSee('hidden-value');
    }

    private function webField(Member $owner, string $value): void
    {
        $profile = Profile::factory()->create(['is_edit_public_flag' => true, 'is_public_web' => true]);
        MemberProfile::factory()->create([
            'member_id' => $owner->getKey(), 'profile_id' => $profile->getKey(),
            'value' => $value, 'visibility' => Visibility::Open,
        ]);
    }

    public function test_classic_shows_the_age_row_to_self(): void
    {
        $this->travelTo(Carbon::parse('2026-06-24'));
        $owner = Member::factory()->create();
        $this->giveBirthday($owner, '1990-06-23');

        $this->actingAs($owner)->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertSee('<th>Age</th>', false)
            ->assertSee('36 years old');
    }

    public function test_classic_hides_age_from_a_non_friend_by_default(): void
    {
        $this->travelTo(Carbon::parse('2026-06-24'));
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        $this->giveBirthday($owner, '1990-06-23'); // AgeVisibility default = Private

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertDontSee('<th>Age</th>', false);
    }

    public function test_modern_payload_carries_the_gated_age(): void
    {
        $this->travelTo(Carbon::parse('2026-06-24'));
        $owner = Member::factory()->create();
        $owner->setPreference(PreferenceKey::AgeVisibility, Visibility::Members);
        $viewer = Member::factory()->create();
        $this->giveBirthday($owner, '1990-06-23');

        $this->actingAs($viewer)->get("/m/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('member/show')
                ->where('profile.age', 36)
            );
    }

    public function test_a_guest_sees_a_web_public_age_on_a_web_public_profile(): void
    {
        // Two gates must both be open for a guest: the profile page itself is web-public, and the SNS
        // allows web-public age + the owner chose Open (OpenPNE 3 profile_page_public_flag × age_public_flag).
        $this->travelTo(Carbon::parse('2026-06-24'));
        $this->setSnsSetting(SnsSettingKey::AllowWebPublicAge, true);
        $owner = Member::factory()->create(['profile_visibility' => Visibility::Open]);
        $owner->setPreference(PreferenceKey::AgeVisibility, Visibility::Open);
        $this->giveBirthday($owner, '1990-06-23');

        $this->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertSee('<th>Age</th>', false)
            ->assertSee('36 years old');
    }

    private function giveBirthday(Member $owner, string $date): void
    {
        $profile = Profile::factory()->create(['name' => 'op_preset_birthday', 'form_type' => 'date']);
        MemberProfile::factory()->create([
            'member_id' => $owner->getKey(), 'profile_id' => $profile->getKey(),
            'value' => $date, 'value_datetime' => $date.' 00:00:00',
        ]);
    }

    public function test_legacy_profile_aliases_redirect_to_the_canonical_url(): void
    {
        $viewer = Member::factory()->create();
        $other = Member::factory()->create();

        // /member/profile = the viewer's own profile; /member/profile/id/{id} = another member's.
        $this->actingAs($viewer)->get('/member/profile')->assertRedirect("/member/{$viewer->getKey()}");
        $this->actingAs($viewer)->get("/member/profile/id/{$other->getKey()}")->assertRedirect("/member/{$other->getKey()}");
        // OpenPNE 3's raw alias had a trailing splat; extra path segments still redirect, not 404.
        $this->actingAs($viewer)->get("/member/profile/id/{$other->getKey()}/extra")->assertRedirect("/member/{$other->getKey()}");
    }

    private function fieldFor(Member $owner, Visibility $visibility, string $value): void
    {
        $profile = Profile::factory()->create(['is_edit_public_flag' => true]);
        MemberProfile::factory()->create([
            'member_id' => $owner->getKey(), 'profile_id' => $profile->getKey(),
            'value' => $value, 'visibility' => $visibility,
        ]);
    }
}
