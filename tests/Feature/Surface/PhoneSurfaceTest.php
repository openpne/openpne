<?php

declare(strict_types=1);

namespace Tests\Feature\Surface;

use App\Models\Member;
use App\Support\Surface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Headers go on each request, never through withHeader(): that one persists for the rest of the
 * test, and most cases here compare a phone request with a desktop one for the same member.
 */
class PhoneSurfaceTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = ['User-Agent' => 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Mobile Safari/537.36'];

    private const DESKTOP = ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36'];

    public function test_an_undecided_member_gets_modern_on_a_phone_and_the_classic_default_on_a_desktop(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/friend/list', self::PHONE)
            ->assertInertia(fn (Assert $page) => $page->component('friend/list'));
        $this->actingAs($member)->get('/friend/list', self::DESKTOP)
            ->assertOk()->assertSee('id="page_friend_list"', false);
    }

    public function test_a_durable_classic_choice_holds_on_a_desktop_but_not_on_a_phone(): void
    {
        $member = Member::factory()->create();
        $member->setPreferredSurface(Surface::Classic);

        $this->actingAs($member)->get('/friend/list', self::PHONE)
            ->assertInertia(fn (Assert $page) => $page->component('friend/list'));
        $this->actingAs($member)->get('/friend/list', self::DESKTOP)
            ->assertOk()->assertSee('id="page_friend_list"', false);
    }

    public function test_a_guest_gets_the_modern_login_screen_on_a_phone(): void
    {
        $this->get('/login', self::PHONE)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/login'));
    }

    public function test_the_feature_fallback_gate_still_outranks_the_phone_gate(): void
    {
        config(['features.friend.modern_status' => 'fallback']);
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/friend/list', self::PHONE)
            ->assertOk()->assertSee('id="page_friend_list"', false);
    }

    public function test_modern_only_is_unaffected_by_the_client(): void
    {
        config(['openpne.surface_mode' => 'modern_only']);
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/friend/list', self::DESKTOP)
            ->assertInertia(fn (Assert $page) => $page->component('friend/list'));
    }

    public function test_the_picker_on_a_phone_shows_the_desktop_surface_with_the_caption(): void
    {
        $member = Member::factory()->create();
        $member->setPreferredSurface(Surface::Classic);

        $this->actingAs($member)->get('/member/config', self::PHONE)
            ->assertInertia(fn (Assert $page) => $page
                ->component('member/config')
                ->where('form.surface.value', 'classic')
                ->where('form.surface.description', Surface::pickerNote()));
    }

    public function test_the_classic_picker_carries_the_caption(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/member/config?category=general', self::DESKTOP)
            ->assertOk()
            ->assertSee(__(Surface::pickerNote()));
    }

    public function test_saving_the_desktop_surface_from_a_phone_is_the_no_op_it_is_on_a_desktop(): void
    {
        // The default is Classic here; a phone shows Modern, but the picker compares against the
        // desktop surface, so re-saving Classic must not pin the member.
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/member/config/surface', ['preferred_surface' => 'classic'], self::PHONE);

        $this->assertDatabaseMissing('member_preferences', [
            'member_id' => $member->id, 'key' => 'preferred_surface',
        ]);
    }

    public function test_a_change_made_from_a_phone_takes_effect_on_the_desktop(): void
    {
        // A phone request is Modern either way, so only a desktop request can show the pin took.
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/member/config/surface', ['preferred_surface' => 'modern'], self::PHONE);

        $this->assertDatabaseHas('member_preferences', [
            'member_id' => $member->id, 'key' => 'preferred_surface', 'value' => 'modern',
        ]);
        $this->actingAs($member)->get('/friend/list', self::DESKTOP)
            ->assertInertia(fn (Assert $page) => $page->component('friend/list'));
    }
}
