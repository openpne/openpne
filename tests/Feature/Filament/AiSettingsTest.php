<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\AiSettings;
use App\Models\AdminUser;
use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class AiSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(AdminUser::factory()->create(), 'admin');
    }

    public function test_it_shows_the_stored_values(): void
    {
        DB::table('sns_settings')->updateOrInsert(['key' => SnsSettingKey::AiAccountsEnabled->value], ['value' => '1']);
        DB::table('sns_settings')->updateOrInsert(['key' => SnsSettingKey::AiAccountLimit->value], ['value' => '7']);

        Livewire::test(AiSettings::class)
            ->assertOk()
            ->assertSet('data.'.SnsSettingKey::AiAccountsEnabled->value, true)
            ->assertSet('data.'.SnsSettingKey::AiAccountLimit->value, 7);
    }

    public function test_it_is_off_before_anyone_saves(): void
    {
        // No row yet: the fail-closed default is what the operator sees, not an empty toggle that
        // implies members are already creating accounts.
        Livewire::test(AiSettings::class)
            ->assertSet('data.'.SnsSettingKey::AiAccountsEnabled->value, false)
            ->assertSet('data.'.SnsSettingKey::AiAccountLimit->value, 3);
    }

    public function test_saving_stores_both_values_and_clears_the_cache(): void
    {
        // The cache is what every read goes through, so a save that skipped it would leave the site
        // behaving as though nothing changed until the TTL expired.
        $this->assertFalse((bool) app(SnsSettingService::class)->get(SnsSettingKey::AiAccountsEnabled));

        Livewire::test(AiSettings::class)
            ->set('data.'.SnsSettingKey::AiAccountsEnabled->value, true)
            ->set('data.'.SnsSettingKey::AiAccountLimit->value, 5)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('1', DB::table('sns_settings')->where('key', SnsSettingKey::AiAccountsEnabled->value)->value('value'));
        $this->assertSame('5', DB::table('sns_settings')->where('key', SnsSettingKey::AiAccountLimit->value)->value('value'));
        $this->assertTrue((bool) app(SnsSettingService::class)->get(SnsSettingKey::AiAccountsEnabled));
        $this->assertSame(5, app(SnsSettingService::class)->get(SnsSettingKey::AiAccountLimit));
    }

    public function test_it_can_be_turned_back_off(): void
    {
        DB::table('sns_settings')->updateOrInsert(['key' => SnsSettingKey::AiAccountsEnabled->value], ['value' => '1']);

        Livewire::test(AiSettings::class)
            ->set('data.'.SnsSettingKey::AiAccountsEnabled->value, false)
            ->call('save');

        $this->assertSame('0', DB::table('sns_settings')->where('key', SnsSettingKey::AiAccountsEnabled->value)->value('value'));
        $this->assertFalse((bool) app(SnsSettingService::class)->get(SnsSettingKey::AiAccountsEnabled));
    }

    public function test_the_limit_refuses_a_value_outside_the_offered_range(): void
    {
        Livewire::test(AiSettings::class)
            ->set('data.'.SnsSettingKey::AiAccountLimit->value, 101)
            ->call('save')
            ->assertHasErrors('data.'.SnsSettingKey::AiAccountLimit->value);

        Livewire::test(AiSettings::class)
            ->set('data.'.SnsSettingKey::AiAccountLimit->value, -1)
            ->call('save')
            ->assertHasErrors('data.'.SnsSettingKey::AiAccountLimit->value);

        Livewire::test(AiSettings::class)
            ->set('data.'.SnsSettingKey::AiAccountLimit->value, '')
            ->call('save')
            ->assertHasErrors('data.'.SnsSettingKey::AiAccountLimit->value);

        $this->assertSame(0, DB::table('sns_settings')->where('key', SnsSettingKey::AiAccountLimit->value)->count());
    }

    public function test_the_page_says_the_switch_is_read_at_creation_only(): void
    {
        // "Allow members to create AI accounts" reads like a live permission; it is not, and an
        // operator deciding to switch it off has to know that on this page. Asserted through __()
        // under an explicit locale, since the panel renders in the site language.
        foreach (['en', 'ja'] as $locale) {
            app()->setLocale($locale);

            $rendered = Livewire::test(AiSettings::class)->html();

            $this->assertStringContainsString(SnsSettingKey::AiAccountsEnabled->label(), $rendered);
            $this->assertStringContainsString(SnsSettingKey::AiAccountLimit->label(), $rendered);
            $this->assertStringContainsString(
                __('Checked only when an account is created. Switching this off stops new ones; the accounts members already own keep working, and their owners can still manage and delete them.'),
                $rendered,
            );
        }
    }

    public function test_a_guest_cannot_reach_it(): void
    {
        auth('admin')->logout();

        $this->get(AiSettings::getUrl())->assertRedirect();
    }
}
