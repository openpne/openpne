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

    public function test_the_classic_page_renders_the_three_sections(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/member/config')
            ->assertOk()
            ->assertSee('id="page_member_config"', false)
            ->assertSee('name="diary_default_visibility"', false)
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
                ->where('form.surface.value', '') // unset = follow the site default
                ->has('form.diary.options'));
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

        $this->actingAs($member)->post('/m/member/config/surface', ['preferred_surface' => 'classic'])
            ->assertRedirect(route('member.config'));
    }

    public function test_resetting_the_surface_follows_the_tenant_default(): void
    {
        // The empty option deletes the row; resolution then follows the tenant default — Modern here,
        // proving a reset is not the same as forcing Classic (Codex Low).
        config(['openpne.tenant_default_surface' => 'modern']);
        $member = Member::factory()->create();
        $member->setPreferredSurface(Surface::Classic);

        $this->actingAs($member)->post('/member/config/surface', ['preferred_surface' => '']);

        $this->assertDatabaseMissing('member_preferences', [
            'member_id' => $member->id, 'key' => 'preferred_surface',
        ]);
        $this->actingAs($member)->get('/friend/list')
            ->assertInertia(fn (Assert $page) => $page->component('friend/list'));
    }
}
