<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\LoginScreenSettings;
use App\Models\AdminUser;
use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The login screen message editor. `sns_settings` is authoritative and the Markdown is stored
 * verbatim; the value is bounded by the TEXT column's byte size, not a character count.
 */
class LoginScreenSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(AdminUser::factory()->create(), 'admin');
    }

    public function test_saving_stores_the_message_verbatim(): void
    {
        Livewire::test(LoginScreenSettings::class)
            ->fillForm(['login_message' => "  # Welcome\n\nJoin us.\n"])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(
            "  # Welcome\n\nJoin us.\n",
            DB::table('sns_settings')->where('key', 'login_message')->value('value'),
        );
    }

    public function test_saved_value_round_trips_into_the_form(): void
    {
        $this->setSnsSetting(SnsSettingKey::LoginMessage, 'Hello, world.');

        Livewire::test(LoginScreenSettings::class)
            ->assertSet('data.login_message', 'Hello, world.');
    }

    public function test_the_form_is_empty_when_no_row_exists(): void
    {
        Livewire::test(LoginScreenSettings::class)
            ->assertSet('data.login_message', '');
    }

    public function test_oversized_value_is_rejected(): void
    {
        // One byte over the TEXT column limit, counted in bytes: 21845 three-byte characters plus one
        // more is 65536 bytes but only 21846 characters, so a char-count max would let it through.
        Livewire::test(LoginScreenSettings::class)
            ->fillForm(['login_message' => str_repeat('あ', 21845).'a'])
            ->call('save')
            ->assertHasErrors('data.login_message');

        $this->assertDatabaseMissing('sns_settings', ['key' => 'login_message']);
    }

    public function test_saving_takes_effect_on_a_reader_that_already_cached_the_setting(): void
    {
        // Prime the cache first: without save()'s clearCache() the login screen would keep serving
        // the old message for the cache TTL.
        $this->assertSame('', app(SnsSettingService::class)->get(SnsSettingKey::LoginMessage));

        Livewire::test(LoginScreenSettings::class)
            ->fillForm(['login_message' => 'Now open to everyone.'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Now open to everyone.', app(SnsSettingService::class)->get(SnsSettingKey::LoginMessage));
    }
}
