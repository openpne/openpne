<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\LookSettings;
use App\Models\AdminUser;
use App\Models\Member;
use App\Services\SnsSettingService;
use App\Support\Look;
use App\Support\SnsSettingKey;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class LookSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(AdminUser::factory()->create(), 'admin');
    }

    public function test_it_shows_the_stored_value(): void
    {
        DB::table('sns_settings')->updateOrInsert(['key' => SnsSettingKey::DefaultLook->value], ['value' => 'unified']);

        // The service hands back the typed Look; the radio holds the id it posts back, so this pins
        // the mapping between the two.
        Livewire::test(LookSettings::class)
            ->assertOk()
            ->assertSet('data.'.SnsSettingKey::DefaultLook->value, 'unified');
    }

    public function test_it_is_standard_before_anyone_saves(): void
    {
        Livewire::test(LookSettings::class)
            ->assertSet('data.'.SnsSettingKey::DefaultLook->value, 'standard');
    }

    public function test_saving_stores_the_value_and_clears_the_cache(): void
    {
        // The cache is what every read goes through, so a save that skipped it would leave the site
        // on the old look until the TTL expired.
        $this->assertSame(Look::Standard, app(SnsSettingService::class)->get(SnsSettingKey::DefaultLook));

        Livewire::test(LookSettings::class)
            ->set('data.'.SnsSettingKey::DefaultLook->value, 'unified')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('unified', DB::table('sns_settings')->where('key', SnsSettingKey::DefaultLook->value)->value('value'));
        $this->assertSame(Look::Unified, app(SnsSettingService::class)->get(SnsSettingKey::DefaultLook));
    }

    public function test_it_can_be_switched_back(): void
    {
        DB::table('sns_settings')->updateOrInsert(['key' => SnsSettingKey::DefaultLook->value], ['value' => 'unified']);

        Livewire::test(LookSettings::class)
            ->set('data.'.SnsSettingKey::DefaultLook->value, 'standard')
            ->call('save');

        $this->assertSame('standard', DB::table('sns_settings')->where('key', SnsSettingKey::DefaultLook->value)->value('value'));
        $this->assertSame(Look::Standard, app(SnsSettingService::class)->get(SnsSettingKey::DefaultLook));
    }

    public function test_the_selectable_set_round_trips_through_the_checkbox_list(): void
    {
        // The service hands back typed looks; the checkbox list holds and posts the stored ids.
        $this->setSnsSetting(SnsSettingKey::SelectableLooks, [Look::Unified]);

        Livewire::test(LookSettings::class)
            ->assertOk()
            ->assertSet('data.'.SnsSettingKey::SelectableLooks->value, ['unified']);
    }

    public function test_nothing_is_selectable_before_anyone_ticks_a_box(): void
    {
        Livewire::test(LookSettings::class)
            ->assertSet('data.'.SnsSettingKey::SelectableLooks->value, []);
    }

    public function test_saving_stores_the_set_as_csv(): void
    {
        Livewire::test(LookSettings::class)
            ->set('data.'.SnsSettingKey::SelectableLooks->value, ['unified', 'standard'])
            ->call('save')
            ->assertHasNoErrors();

        // Registry order, whatever order the boxes were ticked in.
        $this->assertSame('standard,unified', DB::table('sns_settings')->where('key', SnsSettingKey::SelectableLooks->value)->value('value'));
        $this->assertSame([Look::Standard, Look::Unified], app(SnsSettingService::class)->get(SnsSettingKey::SelectableLooks));
    }

    public function test_a_look_added_to_the_registry_is_offered_and_accepted(): void
    {
        // Both controls read Look::cases() and Filament validates against the offered options, so
        // tabbed, named nowhere else, proves registering a look is the whole of publishing it.
        Livewire::test(LookSettings::class)
            ->set('data.'.SnsSettingKey::DefaultLook->value, 'tabbed')
            ->set('data.'.SnsSettingKey::SelectableLooks->value, ['tabbed'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(Look::Tabbed, app(SnsSettingService::class)->get(SnsSettingKey::DefaultLook));
        $this->assertSame([Look::Tabbed], app(SnsSettingService::class)->get(SnsSettingKey::SelectableLooks));
    }

    public function test_an_id_no_look_answers_to_is_rejected(): void
    {
        Livewire::test(LookSettings::class)
            ->set('data.'.SnsSettingKey::SelectableLooks->value, ['nonesuch'])
            ->call('save')
            ->assertHasErrors('data.'.SnsSettingKey::SelectableLooks->value.'.0');

        $this->assertNull(DB::table('sns_settings')->where('key', SnsSettingKey::SelectableLooks->value)->value('value'));
    }

    public function test_narrowing_the_set_releases_only_the_members_left_outside_it(): void
    {
        // Both directions in one save: the set becomes {unified} (the new default, nothing ticked),
        // so a member on `unified` keeps their row and one on `standard` loses it.
        $this->setSnsSetting(SnsSettingKey::SelectableLooks, Look::cases());
        $kept = Member::factory()->create();
        $kept->setPreferredLook(Look::Unified);
        $released = Member::factory()->create();
        $released->setPreferredLook(Look::Standard);

        Livewire::test(LookSettings::class)
            ->set('data.'.SnsSettingKey::DefaultLook->value, 'unified')
            ->set('data.'.SnsSettingKey::SelectableLooks->value, [])
            ->call('save')
            ->assertHasNoErrors()
            // The blast radius is reported rather than left to be discovered.
            ->assertNotified(Notification::make()
                ->success()
                ->title(__('Saved'))
                ->body(__('Cleared the layout choice of :count members', ['count' => 1])));

        $this->assertSame(Look::Unified, $kept->fresh()?->preferredLook());
        $this->assertNull($released->fresh()?->preferredLook());
    }

    public function test_a_save_that_strands_nobody_says_nothing_about_it(): void
    {
        $this->setSnsSetting(SnsSettingKey::SelectableLooks, Look::cases());
        $member = Member::factory()->create();
        $member->setPreferredLook(Look::Unified);

        Livewire::test(LookSettings::class)
            ->set('data.'.SnsSettingKey::SelectableLooks->value, ['unified'])
            ->call('save')
            ->assertNotified(Notification::make()->success()->title(__('Saved')));

        $this->assertSame(Look::Unified, $member->fresh()?->preferredLook());
    }

    public function test_a_guest_cannot_reach_it(): void
    {
        auth('admin')->logout();

        $this->get(LookSettings::getUrl())->assertRedirect();
    }
}
