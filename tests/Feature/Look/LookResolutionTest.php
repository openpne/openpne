<?php

declare(strict_types=1);

namespace Tests\Feature\Look;

use App\Models\Member;
use App\Support\Look;
use App\Support\LookResolver;
use App\Support\PreferenceKey;
use App\Support\SnsSettingKey;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The order the three layers answer in — guest clamp, durable member choice, site default — and
 * what each of them refuses to honour.
 */
class LookResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A look is a Modern-surface concept, so every assertion here is about a Modern render.
        config(['openpne.surface_mode' => 'modern_default']);
    }

    /** Offer both looks, with $default the site's. */
    private function offerBoth(Look $default = Look::Standard): void
    {
        $this->setSnsSetting(SnsSettingKey::DefaultLook, $default);
        $this->setSnsSetting(SnsSettingKey::SelectableLooks, Look::cases());
        $this->freshRequestState();
    }

    public function test_a_guest_gets_the_standard_look_whatever_the_site_offers(): void
    {
        // The clamp is first for a reason: the pages a guest does reach (a web-public profile) have
        // no member for a look's serializer to render against.
        $owner = Member::factory()->create(['profile_visibility' => Visibility::Open]);
        $this->offerBoth(Look::Unified);

        $this->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('member/show')
                ->where('look', 'standard')
            );
    }

    public function test_a_stored_choice_outranks_the_site_default(): void
    {
        $viewer = Member::factory()->create();
        $this->offerBoth();

        // Nothing chosen: the site default.
        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('look', 'standard'));

        // A stored choice beats the default.
        $viewer->setPreferredLook(Look::Unified);
        $this->freshRequestState();
        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('look', 'unified'));
    }

    public function test_a_stored_choice_the_site_stopped_offering_is_ignored(): void
    {
        // The safety belt for a row the admin cleanup has not reached (a look dropped from the
        // registry, a hand-written row): read-time it simply does not answer.
        $viewer = Member::factory()->create();
        $viewer->setPreferredLook(Look::Unified);
        $this->setSnsSetting(SnsSettingKey::SelectableLooks, []);
        $this->freshRequestState();

        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('look', 'standard'));
    }

    public function test_a_corrupt_stored_choice_falls_through_to_the_site_default(): void
    {
        $viewer = Member::factory()->create();
        DB::table('member_preferences')->insert([
            'member_id' => $viewer->getKey(),
            'key' => PreferenceKey::PreferredLook->value,
            'value' => 'nonsense',
        ]);
        $this->offerBoth(Look::Unified);

        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('look', 'unified'));
    }

    public function test_the_selectable_set_always_holds_the_site_default(): void
    {
        // Nothing ticked, and the default is still what "follow the site default" follows.
        $this->setSnsSetting(SnsSettingKey::DefaultLook, Look::Unified);
        $this->setSnsSetting(SnsSettingKey::SelectableLooks, []);
        $this->freshRequestState();

        $this->assertSame([Look::Unified], LookResolver::selectable());

        // And a ticked look joins it, in registry order however it arrived.
        $this->setSnsSetting(SnsSettingKey::SelectableLooks, [Look::Standard]);
        $this->freshRequestState();

        $this->assertSame([Look::Standard, Look::Unified], LookResolver::selectable());
    }

    public function test_the_page_swap_follows_the_member_choice(): void
    {
        // End to end: the look decides which page component the route renders, not just the chrome.
        $viewer = Member::factory()->create();
        $this->offerBoth();

        $this->actingAs($viewer)->get("/member/{$viewer->getKey()}")
            ->assertInertia(fn ($page) => $page->component('member/show'));

        $viewer->setPreferredLook(Look::Unified);
        $this->freshRequestState();

        $this->actingAs($viewer)->get("/member/{$viewer->getKey()}")
            ->assertInertia(fn ($page) => $page->component('unified/member'));

        // Home is not among the swapped routes: the digest is what /dashboard renders under every
        // look.
        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn ($page) => $page->component('dashboard'));
    }
}
