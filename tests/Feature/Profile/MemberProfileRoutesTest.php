<?php

namespace Tests\Feature\Profile;

use App\Models\Member;
use App\Models\MemberProfile;
use App\Models\Profile;
use App\Support\Visibility;
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

    public function test_legacy_profile_aliases_redirect_to_the_canonical_url(): void
    {
        $viewer = Member::factory()->create();
        $other = Member::factory()->create();

        // /member/profile = the viewer's own profile; /member/profile/id/{id} = another member's.
        $this->actingAs($viewer)->get('/member/profile')->assertRedirect("/member/{$viewer->getKey()}");
        $this->actingAs($viewer)->get("/member/profile/id/{$other->getKey()}")->assertRedirect("/member/{$other->getKey()}");
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
