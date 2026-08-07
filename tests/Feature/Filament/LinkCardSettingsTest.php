<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\LinkCardSettings;
use App\Models\AdminUser;
use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class LinkCardSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(AdminUser::factory()->create(), 'admin');
    }

    public function test_it_shows_the_stored_value(): void
    {
        DB::table('sns_settings')->updateOrInsert(['key' => SnsSettingKey::LinkCardEnabled->value], ['value' => '1']);

        Livewire::test(LinkCardSettings::class)
            ->assertOk()
            ->assertSet('data.'.SnsSettingKey::LinkCardEnabled->value, true);
    }

    public function test_it_is_off_before_anyone_saves(): void
    {
        // No row yet: the fail-closed default is what the operator sees, not an empty toggle that
        // implies the feature is already running.
        Livewire::test(LinkCardSettings::class)
            ->assertSet('data.'.SnsSettingKey::LinkCardEnabled->value, false);
    }

    public function test_saving_stores_the_value_and_clears_the_cache(): void
    {
        // The cache is what every read goes through, so a save that skipped it would leave the site
        // behaving as though nothing changed until the TTL expired.
        $this->assertFalse((bool) app(SnsSettingService::class)->get(SnsSettingKey::LinkCardEnabled));

        Livewire::test(LinkCardSettings::class)
            ->set('data.'.SnsSettingKey::LinkCardEnabled->value, true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('1', DB::table('sns_settings')->where('key', SnsSettingKey::LinkCardEnabled->value)->value('value'));
        $this->assertTrue((bool) app(SnsSettingService::class)->get(SnsSettingKey::LinkCardEnabled));
    }

    public function test_it_can_be_turned_back_off(): void
    {
        DB::table('sns_settings')->updateOrInsert(['key' => SnsSettingKey::LinkCardEnabled->value], ['value' => '1']);

        Livewire::test(LinkCardSettings::class)
            ->set('data.'.SnsSettingKey::LinkCardEnabled->value, false)
            ->call('save');

        $this->assertSame('0', DB::table('sns_settings')->where('key', SnsSettingKey::LinkCardEnabled->value)->value('value'));
        $this->assertFalse((bool) app(SnsSettingService::class)->get(SnsSettingKey::LinkCardEnabled));
    }

    public function test_the_page_says_what_turning_it_on_does(): void
    {
        // "Show link previews" does not imply the server reaches out to every linked page, from
        // private posts included — so the page has to say so where the decision is made.
        // Asserted through __() under an explicit locale: the admin panel renders in the site
        // language, so hardcoding the English copy would pass or fail depending on that setting
        // rather than on whether the page carries the warning.
        foreach (['en', 'ja'] as $locale) {
            app()->setLocale($locale);

            $rendered = Livewire::test(LinkCardSettings::class)->html();

            $this->assertStringContainsString(SnsSettingKey::LinkCardEnabled->label(), $rendered);
            $this->assertStringContainsString(
                __('This site will request the pages members link to, including from private posts and posts limited to %friends%. Those sites can tell the link was shared here.'),
                $rendered,
            );
        }
    }

    public function test_a_guest_cannot_reach_it(): void
    {
        auth('admin')->logout();

        $this->get(LinkCardSettings::getUrl())->assertRedirect();
    }
}
