<?php

declare(strict_types=1);

namespace Tests\Feature\Member;

use App\Features\Member\MemberConfigController;
use App\Models\Member;
use App\Support\Look;
use App\Support\LookResolver;
use App\Support\PreferenceKey;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * The member's layout section: what it offers, and the try-on cycle behind it — preview parks the
 * choice in the session, confirm is what writes it, cancel and a surface switch drop it.
 */
class MemberConfigLookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['openpne.surface_mode' => 'modern_default']);
    }

    private function offerBoth(): void
    {
        $this->setSnsSetting(SnsSettingKey::SelectableLooks, Look::cases());
        $this->freshRequestState();
    }

    /** A warm preview session, as the preview POST leaves it. */
    private function previewing(Look $look, bool $pin = true): array
    {
        return [LookResolver::PREVIEW_SESSION_KEY => ['look' => $look->value, 'pin' => $pin]];
    }

    private function storedLook(Member $member): ?string
    {
        return DB::table('member_preferences')
            ->where('member_id', $member->getKey())
            ->where('key', PreferenceKey::PreferredLook->value)
            ->value('value');
    }

    public function test_the_section_is_absent_while_the_site_offers_one_look(): void
    {
        // The default alone is not a choice, so the picker would be a card nobody can move off.
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/member/config')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->missing('form.look'));
    }

    public function test_the_section_offers_the_selectable_looks_and_names_the_default(): void
    {
        $member = Member::factory()->create();
        $member->setPreferredLook(Look::Unified);
        $this->offerBoth();

        $this->actingAs($member)->get('/member/config')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('form.look.options.0.value', 'standard')
                ->where('form.look.options.0.label', 'Standard')
                ->where('form.look.options.1.value', 'unified')
                // Labels and descriptions travel as translation keys, like every other look label.
                ->where('form.look.options.1.description', 'The experimental layout that renders home, profiles and %communities% in one shared shape.')
                ->where('form.look.current', 'unified')
                ->where('form.look.default', ['value' => 'standard', 'label' => 'Standard'])
            );
    }

    public function test_the_stored_choice_is_what_preselects_not_the_look_being_rendered(): void
    {
        // An undecided member lands on "follow the site default" even while previewing something.
        $member = Member::factory()->create();
        $this->offerBoth();

        $this->actingAs($member)->withSession($this->previewing(Look::Unified))->get('/member/config')
            ->assertInertia(fn ($page) => $page
                ->where('form.look.current', null)
                ->where('look', 'unified')
            );
    }

    public function test_previewing_a_look_the_site_does_not_offer_is_refused(): void
    {
        // A hard gate, not a hidden control: the section is absent, and a crafted post is still 403.
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/member/config/look/preview', ['choice' => 'unified'])
            ->assertForbidden();

        $this->assertNull($this->storedLook($member));
    }

    public function test_previewing_an_unknown_choice_is_refused(): void
    {
        $member = Member::factory()->create();
        $this->offerBoth();

        $this->actingAs($member)->post('/member/config/look/preview', ['choice' => 'nonesuch'])
            ->assertForbidden();
    }

    public function test_previewing_parks_the_choice_in_the_session_without_writing_anything(): void
    {
        $member = Member::factory()->create();
        $this->offerBoth();

        $this->actingAs($member)->post('/member/config/look/preview', ['choice' => 'unified'])
            ->assertSessionHas(LookResolver::PREVIEW_SESSION_KEY, ['look' => 'unified', 'pin' => true]);

        // Trying a look on is not choosing it.
        $this->assertNull($this->storedLook($member));
    }

    public function test_previewing_the_site_default_records_the_intent_to_follow_it(): void
    {
        // `pin` false is the difference between "show me standard" and "show me whatever the site
        // says" — the same look id today, and different once an operator moves the default.
        $member = Member::factory()->create();
        $this->offerBoth();

        $this->actingAs($member)->post('/member/config/look/preview', ['choice' => 'default'])
            ->assertSessionHas(LookResolver::PREVIEW_SESSION_KEY, ['look' => 'standard', 'pin' => false]);
    }

    public function test_confirming_a_pinned_preview_writes_the_choice_and_ends_the_trial(): void
    {
        $member = Member::factory()->create();
        $this->offerBoth();

        $this->actingAs($member)->withSession($this->previewing(Look::Unified))
            ->post('/member/config/look')
            ->assertRedirect('/member/config')
            ->assertSessionMissing(LookResolver::PREVIEW_SESSION_KEY);

        $this->assertSame('unified', $this->storedLook($member));
    }

    public function test_confirming_a_follow_the_default_preview_clears_the_choice(): void
    {
        // Not a write of the current default: the member goes back to following whatever it becomes.
        $member = Member::factory()->create();
        $member->setPreferredLook(Look::Unified);
        $this->offerBoth();

        $this->actingAs($member)->withSession($this->previewing(Look::Standard, pin: false))
            ->post('/member/config/look')
            ->assertSessionMissing(LookResolver::PREVIEW_SESSION_KEY);

        $this->assertNull($this->storedLook($member));
    }

    public function test_confirming_with_no_preview_running_writes_nothing(): void
    {
        // The POST carries no choice of its own, so there is nothing to apply.
        $member = Member::factory()->create();
        $member->setPreferredLook(Look::Unified);
        $this->offerBoth();

        $this->actingAs($member)->post('/member/config/look')
            ->assertRedirect('/member/config');

        $this->assertSame('unified', $this->storedLook($member));
    }

    public function test_the_confirm_gate_refuses_an_intent_that_survives_to_the_controller(): void
    {
        // The shared-prop pass sweeps a stale intent before the controller runs, so no HTTP request
        // can reach updateLook()'s own 403 belt — it exists for the set narrowing between the two
        // (a true TOCTOU). Exercised directly, middleware bypassed, so removing the belt goes red.
        $member = Member::factory()->create();
        $this->setSnsSetting(SnsSettingKey::SelectableLooks, []);
        $this->freshRequestState();
        $this->actingAs($member);

        $request = Request::create('/member/config/look', 'POST');
        $request->setLaravelSession(app('session.store'));
        $request->session()->put(LookResolver::PREVIEW_SESSION_KEY, ['look' => 'unified', 'pin' => true]);
        $request->setUserResolver(static fn () => $member);

        try {
            app(MemberConfigController::class)->updateLook($request);
            $this->fail('The stale intent was applied instead of being refused.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertNull($this->storedLook($member));
    }

    public function test_confirming_a_look_the_site_stopped_offering_writes_nothing(): void
    {
        // The set can narrow while a preview is warm. The stale session is dropped by the shared-prop
        // pass before the controller runs (self-heal in LookResolver::preview()), so the confirm
        // finds no intent and applies nothing; updateLook()'s own 403 gate stays as the last belt
        // for an intent that somehow survives to it.
        $member = Member::factory()->create();

        $this->actingAs($member)->withSession($this->previewing(Look::Unified))
            ->post('/member/config/look')
            ->assertRedirect('/member/config')
            ->assertSessionMissing(LookResolver::PREVIEW_SESSION_KEY);

        $this->assertNull($this->storedLook($member));
    }

    public function test_cancelling_drops_the_preview_and_keeps_the_stored_choice(): void
    {
        $member = Member::factory()->create();
        $member->setPreferredLook(Look::Unified);
        $this->offerBoth();

        $this->actingAs($member)->withSession($this->previewing(Look::Standard))
            ->post('/member/config/look/preview/stop')
            ->assertSessionMissing(LookResolver::PREVIEW_SESSION_KEY);

        $this->assertSame('unified', $this->storedLook($member));
    }

    public function test_crossing_to_classic_ends_the_trial(): void
    {
        // Classic draws no preview bar, so a preview left running there could not be confirmed or
        // cancelled — and would keep answering the moment the member came back.
        $member = Member::factory()->create();
        $this->offerBoth();

        $this->actingAs($member)->withSession($this->previewing(Look::Unified))
            ->post('/member/config/surface', ['preferred_surface' => 'classic'])
            ->assertSessionMissing(LookResolver::PREVIEW_SESSION_KEY);
    }

    public function test_the_shell_ships_the_preview_for_the_bar_to_render(): void
    {
        $member = Member::factory()->create();
        $this->offerBoth();

        $this->actingAs($member)->withSession($this->previewing(Look::Unified))->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('lookPreview.look', 'unified')
                ->where('lookPreview.pin', true)
                // A translation key, like every look label on the wire.
                ->where('lookPreview.label', 'Unified (experimental)')
            );

        // The test client carries the session between requests; drop it to be the member arriving
        // with no trial running.
        $this->flushSession();

        $this->actingAs($member)->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('lookPreview', null));
    }

    public function test_a_guest_cannot_reach_the_look_routes(): void
    {
        foreach (['/member/config/look', '/member/config/look/preview', '/member/config/look/preview/stop'] as $route) {
            $this->post($route, ['choice' => 'standard'])->assertRedirect('/login');
        }
    }
}
