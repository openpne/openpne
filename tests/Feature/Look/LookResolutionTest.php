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
 * The order the four layers answer in — guest clamp, session preview, durable member choice, site
 * default — and what each of them refuses to honour.
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

    /** A warm preview session, as the preview POST leaves it. */
    private function previewing(Look $look, bool $pin = true): array
    {
        return [LookResolver::PREVIEW_SESSION_KEY => ['look' => $look->value, 'pin' => $pin]];
    }

    public function test_the_guest_clamp_answers_before_a_warm_preview_session(): void
    {
        // The clamp is first for a reason: a signed-out request can carry the session of whoever
        // used this browser before, and a web-public profile has no member to resolve a look for.
        $owner = Member::factory()->create(['profile_visibility' => Visibility::Open]);
        $this->offerBoth();

        $this->withSession($this->previewing(Look::Unified))
            ->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('member/show')
                ->where('look', 'standard')
                // The bar is member chrome: a guest is offered nothing to keep or cancel.
                ->where('lookPreview', null)
            );
    }

    public function test_a_preview_outranks_the_stored_choice_which_outranks_the_site_default(): void
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

        // And a preview beats the stored choice — it is what the member asked to see right now.
        $this->actingAs($viewer)->withSession($this->previewing(Look::Standard))->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('look', 'standard'));
    }

    public function test_a_preview_of_a_look_the_site_stopped_offering_is_ignored(): void
    {
        $viewer = Member::factory()->create();
        // Only the default is selectable, so a session naming the other one is stale.
        $this->setSnsSetting(SnsSettingKey::SelectableLooks, []);
        $this->freshRequestState();

        $this->actingAs($viewer)->withSession($this->previewing(Look::Unified))->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('look', 'standard')
                // ...and the bar that would offer to keep it is gone with it.
                ->where('lookPreview', null)
            );
    }

    public function test_a_stale_preview_session_is_dropped_rather_than_left_to_wake_up_again(): void
    {
        // Ignoring the entry but keeping it would revive the bar — with no action from the
        // member — the day the administrator re-offers the look. Once unrenderable, it is gone.
        $viewer = Member::factory()->create();
        $this->setSnsSetting(SnsSettingKey::SelectableLooks, []);
        $this->freshRequestState();

        $this->actingAs($viewer)->withSession($this->previewing(Look::Unified))->get('/dashboard')
            ->assertSessionMissing(LookResolver::PREVIEW_SESSION_KEY);
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

    public function test_the_page_swap_follows_the_member_choice_and_then_the_preview(): void
    {
        // End to end: the look decides which page component the route renders, not just the chrome.
        $viewer = Member::factory()->create();
        $this->offerBoth();
        $viewer->setPreferredLook(Look::Unified);
        $this->freshRequestState();

        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn ($page) => $page->component('unified/home'));

        $this->actingAs($viewer)->withSession($this->previewing(Look::Standard))->get('/dashboard')
            ->assertInertia(fn ($page) => $page->component('dashboard'));
    }
}
