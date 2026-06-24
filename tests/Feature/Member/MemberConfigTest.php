<?php

namespace Tests\Feature\Member;

use App\Models\Member;
use App\Support\PreferenceKey;
use App\Support\Surface;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MemberConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get('/member/config')->assertRedirect('/login');
    }

    public function test_the_classic_page_renders_the_config_sections(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/member/config')
            ->assertOk()
            ->assertSee('id="page_member_config"', false)
            ->assertSee('name="diary_default_visibility"', false)
            ->assertSee('id="member_config_age"', false)
            ->assertSee('name="age_visibility"', false)
            ->assertSee('Who can see your age')
            ->assertSee(route('locale.switch'), false)
            ->assertSee('name="preferred_surface"', false);
    }

    public function test_the_modern_page_renders_the_inertia_component(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/m/member/config')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('member/config')
                ->where('form.surface.value', 'classic') // preselected to the current surface (tenant default)
                ->where('form.surface.options', fn ($options) => count($options) === 2) // binary: no "default" option
                ->has('form.diary.options')
                ->where('form.age.value', '3') // default Private
                ->where('form.age.options', fn ($options) => count($options) === 3) // Members/Friends/Private, no Open
            );
    }

    public function test_the_access_block_category_redirects_to_the_block_list(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/member/config?category=accessBlock')
            ->assertRedirect(route('block.list'));
    }

    public function test_updating_the_diary_default_writes_the_preference(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/member/config/diary', [
            'diary_default_visibility' => (string) Visibility::Friends->value,
        ])->assertRedirect(route('member.config'));

        $this->assertDatabaseHas('member_preferences', [
            'member_id' => $member->id, 'key' => 'diary_default_visibility', 'value' => '2',
        ]);
    }

    public function test_updating_the_diary_default_rejects_an_invalid_value(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/member/config/diary', ['diary_default_visibility' => '99'])
            ->assertSessionHasErrors('diary_default_visibility');

        $this->assertDatabaseMissing('member_preferences', [
            'member_id' => $member->id, 'key' => 'diary_default_visibility',
        ]);
    }

    public function test_updating_age_visibility_writes_the_preference(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/member/config/age', [
            'age_visibility' => (string) Visibility::Friends->value,
        ])->assertRedirect(route('member.config'));

        $this->assertDatabaseHas('member_preferences', [
            'member_id' => $member->id, 'key' => 'age_visibility', 'value' => '2',
        ]);
    }

    public function test_updating_age_visibility_rejects_an_invalid_value(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/member/config/age', ['age_visibility' => '99'])
            ->assertSessionHasErrors('age_visibility');

        $this->assertDatabaseMissing('member_preferences', [
            'member_id' => $member->id, 'key' => 'age_visibility',
        ]);
    }

    public function test_updating_age_visibility_rejects_web_public(): void
    {
        // Age is never web-public (guests are fail-closed), so Open is not an accepted choice.
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/member/config/age', [
            'age_visibility' => (string) Visibility::Open->value,
        ])->assertSessionHasErrors('age_visibility');

        $this->assertDatabaseMissing('member_preferences', [
            'member_id' => $member->id, 'key' => 'age_visibility',
        ]);
    }

    public function test_changing_the_surface_alone_preserves_a_stored_open_diary_default(): void
    {
        // Web-public off: DiaryVisibility::defaultFor() clamps a stored Open to Members at read time,
        // but the stored row must stay Open — a surface change must not write the clamped value back.
        config(['openpne.diary.allow_web_public' => false]);
        $member = Member::factory()->create();
        $member->setPreference(PreferenceKey::DiaryDefaultVisibility, Visibility::Open);

        $this->actingAs($member)->post('/member/config/surface', ['preferred_surface' => 'modern']);

        $this->assertDatabaseHas('member_preferences', [
            'member_id' => $member->id, 'key' => 'diary_default_visibility', 'value' => '0',
        ]);
    }

    public function test_a_durable_surface_choice_drives_resolution_on_other_features(): void
    {
        $member = Member::factory()->create();

        // Default tenant surface is Classic; choosing Modern flips a canonical feature route to Modern.
        $this->actingAs($member)->post('/member/config/surface', ['preferred_surface' => 'modern']);
        $this->assertDatabaseHas('member_preferences', [
            'member_id' => $member->id, 'key' => 'preferred_surface', 'value' => 'modern',
        ]);
        $this->actingAs($member)->get('/friend/list')
            ->assertInertia(fn (Assert $page) => $page->component('friend/list'));

        // Switching to Classic flips it back.
        $this->actingAs($member)->post('/member/config/surface', ['preferred_surface' => 'classic']);
        $this->actingAs($member)->get('/friend/list')
            ->assertOk()->assertSee('id="page_friend_list"', false);
    }

    public function test_a_classic_choice_from_the_modern_page_lands_on_the_canonical_url(): void
    {
        // The explicit /m/* URL is top of SurfaceResolver's order, so a Classic choice must leave it
        // for the canonical config URL, or the page would stay Modern (Codex High 2).
        $member = Member::factory()->create();
        $member->setPreferredSurface(Surface::Modern); // currently Modern, so choosing Classic is a real change

        $this->actingAs($member)->post('/m/member/config/surface', ['preferred_surface' => 'classic'])
            ->assertRedirect(route('member.config'));

        $this->assertDatabaseHas('member_preferences', [
            'member_id' => $member->id, 'key' => 'preferred_surface', 'value' => 'classic',
        ]);
    }

    public function test_modern_only_tenant_shows_modern_and_does_not_false_pin(): void
    {
        // The "current surface" must honour the modern_only hard gate, not just the tenant default.
        // Otherwise an unset member on a modern_only tenant (default Classic) sees Modern, but the
        // form would call the current surface Classic and selecting Modern would wrongly pin them.
        config(['openpne.tenant_mode' => 'modern_only', 'openpne.tenant_default_surface' => 'classic']);
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/m/member/config')
            ->assertInertia(fn (Assert $page) => $page->where('form.surface.value', 'modern'));

        $this->actingAs($member)->post('/member/config/surface', ['preferred_surface' => 'modern']);

        $this->assertDatabaseMissing('member_preferences', [
            'member_id' => $member->id, 'key' => 'preferred_surface',
        ]);
    }

    public function test_saving_the_current_surface_is_a_no_op_so_an_unset_member_stays_unset(): void
    {
        // Binary UI has no "follow default" option; instead, saving the surface the member is already
        // on never pins them, so the operator can still move unset members later. Default is Modern
        // here; an unset member saving Modern stays unset and keeps following the default.
        config(['openpne.tenant_default_surface' => 'modern']);
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/member/config/surface', ['preferred_surface' => 'modern']);

        $this->assertDatabaseMissing('member_preferences', [
            'member_id' => $member->id, 'key' => 'preferred_surface',
        ]);
        $this->actingAs($member)->get('/friend/list')
            ->assertInertia(fn (Assert $page) => $page->component('friend/list'));
    }
}
