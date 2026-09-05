<?php

declare(strict_types=1);

namespace Tests\Feature\Member;

use App\Models\Member;
use App\Support\Look;
use App\Support\PreferenceKey;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

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

    private function storedLook(Member $member): ?string
    {
        return DB::table('member_preferences')
            ->where('member_id', $member->getKey())
            ->where('key', PreferenceKey::PreferredLook->value)
            ->value('value');
    }

    public function test_the_settings_row_is_absent_while_the_site_offers_one_look(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/member/config')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->missing('form.look'));
    }

    public function test_the_settings_row_names_the_chosen_look(): void
    {
        $member = Member::factory()->create();
        $member->setPreferredLook(Look::Unified);
        $this->offerBoth();

        $this->actingAs($member)->get('/member/config')
            ->assertOk()
            // A translation key, like every look label on the wire.
            ->assertInertia(fn ($page) => $page->where('form.look.current', 'Unified (experimental)'));
    }

    public function test_the_settings_row_reads_as_following_while_nothing_is_chosen(): void
    {
        $member = Member::factory()->create();
        $this->offerBoth();

        $this->actingAs($member)->get('/member/config')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('form.look.current', null)
                ->where('form.look.default', 'Standard')
            );
    }

    public function test_the_picker_offers_the_selectable_looks_and_names_the_default(): void
    {
        $member = Member::factory()->create();
        $member->setPreferredLook(Look::Unified);
        $this->offerBoth();

        $this->actingAs($member)->get('/member/config/look')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('member/config/look')
                ->where('lookChoice.options.0.value', 'standard')
                ->where('lookChoice.options.0.label', 'Standard')
                ->where('lookChoice.options.1.value', 'unified')
                // Labels and descriptions travel as translation keys, like every other look label.
                ->where('lookChoice.options.1.description', 'The experimental layout that renders profiles and %communities% in one shared shape.')
                ->where('lookChoice.current', 'unified')
                ->where('lookChoice.default', ['value' => 'standard', 'label' => 'Standard'])
                // A page prop named `look` would win the Inertia merge and hand the shell an object
                // where it reads an id.
                ->where('look', 'unified')
            );
    }

    public function test_the_picker_preselects_following_when_the_stored_look_is_no_longer_offered(): void
    {
        $member = Member::factory()->create();
        $member->setPreferredLook(Look::Tabbed);
        $this->setSnsSetting(SnsSettingKey::SelectableLooks, [Look::Unified]);
        $this->freshRequestState();

        $this->actingAs($member)->get('/member/config/look')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('lookChoice.current', null));
    }

    public function test_the_picker_sends_a_member_back_while_the_site_offers_one_look(): void
    {
        // The same gate as the settings row, which is why there is no way here from the UI.
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/member/config/look')
            ->assertRedirect('/member/config');
    }

    public function test_choosing_a_look_writes_it_and_lands_back_on_the_picker(): void
    {
        $member = Member::factory()->create();
        $this->offerBoth();

        $this->actingAs($member)->post('/member/config/look', ['choice' => 'unified'])
            ->assertRedirect('/member/config/look');

        $this->assertSame('unified', $this->storedLook($member));
    }

    public function test_the_choice_answers_a_running_spa_with_a_full_page_load(): void
    {
        $member = Member::factory()->create();
        $this->offerBoth();

        $this->actingAs($member)
            ->post('/member/config/look', ['choice' => 'unified'], ['X-Inertia' => 'true'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', url('/member/config/look'));
    }

    public function test_choosing_the_site_default_clears_the_choice(): void
    {
        // Not a write of the current default: the member goes back to following whatever it becomes.
        $member = Member::factory()->create();
        $member->setPreferredLook(Look::Unified);
        $this->offerBoth();

        $this->actingAs($member)->post('/member/config/look', ['choice' => 'default'])
            ->assertRedirect('/member/config/look');

        $this->assertNull($this->storedLook($member));
    }

    public function test_choosing_a_look_the_site_does_not_offer_is_refused(): void
    {
        // A hard gate, not a hidden control: the picker is absent, and a crafted post is still 403.
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/member/config/look', ['choice' => 'unified'])
            ->assertForbidden();

        $this->assertNull($this->storedLook($member));
    }

    public function test_choosing_a_look_dropped_from_the_set_leaves_the_stored_one_alone(): void
    {
        // The set can narrow between the page render and the save; the refusal must not be mistaken
        // for a reset, which would strip a choice the member never asked to give up.
        $member = Member::factory()->create();
        $member->setPreferredLook(Look::Unified);
        $this->setSnsSetting(SnsSettingKey::SelectableLooks, [Look::Unified]);
        $this->freshRequestState();

        $this->actingAs($member)->post('/member/config/look', ['choice' => 'tabbed'])
            ->assertForbidden();

        $this->assertSame('unified', $this->storedLook($member));
    }

    public function test_choosing_an_unknown_value_is_refused(): void
    {
        $member = Member::factory()->create();
        $this->offerBoth();

        $this->actingAs($member)->post('/member/config/look', ['choice' => 'nonesuch'])
            ->assertForbidden();

        $this->assertNull($this->storedLook($member));
    }

    public function test_a_guest_cannot_reach_the_look_routes(): void
    {
        $this->post('/member/config/look', ['choice' => 'standard'])->assertRedirect('/login');
        $this->get('/member/config/look')->assertRedirect('/login');
    }
}
