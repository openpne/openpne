<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\SnsBaseSettings;
use App\Models\AdminUser;
use App\Support\SettingGroup;
use App\Support\SnsSettingKey;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The SNS base-settings editor, guarding the same "do not persist values that match the default"
 * invariant as the term editor, plus that the page only ever exposes its own (Base) settings group.
 */
class SnsBaseSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(AdminUser::factory()->create(), 'admin');
    }

    public function test_changing_a_field_from_default_inserts_a_row(): void
    {
        Livewire::test(SnsBaseSettings::class)
            ->fillForm(['sns_name' => 'My Community'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sns_settings', [
            'key' => 'sns_name',
            'value' => 'My Community',
        ]);
    }

    public function test_resetting_a_field_to_its_default_deletes_the_row(): void
    {
        DB::table('sns_settings')->insert(['key' => 'sns_name', 'value' => 'My Community']);

        Livewire::test(SnsBaseSettings::class)
            ->fillForm(['sns_name' => (string) config('app.name')])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('sns_settings', ['key' => 'sns_name']);
    }

    public function test_save_with_only_default_values_leaves_the_table_empty(): void
    {
        // The form mounts pre-filled with the defaults; submitting unchanged must not seed rows.
        Livewire::test(SnsBaseSettings::class)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(0, DB::table('sns_settings')->count());
    }

    public function test_required_sns_name_cannot_be_blank(): void
    {
        Livewire::test(SnsBaseSettings::class)
            ->fillForm(['sns_name' => ''])
            ->call('save')
            ->assertHasErrors('data.sns_name');
    }

    public function test_admin_mail_address_must_be_an_email(): void
    {
        Livewire::test(SnsBaseSettings::class)
            ->fillForm(['admin_mail_address' => 'not-an-email'])
            ->call('save')
            ->assertHasErrors('data.admin_mail_address');
    }

    public function test_form_reflects_persisted_override_after_save(): void
    {
        Livewire::test(SnsBaseSettings::class)
            ->fillForm(['sns_name' => 'My Community'])
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('data.sns_name', 'My Community');
    }

    public function test_page_only_exposes_the_base_settings_group(): void
    {
        // Regression guard for when the Auth group (registration / CAPTCHA) is added: those keys
        // must stay on their own page, never leak into the identity base-settings form.
        $data = Livewire::test(SnsBaseSettings::class)->get('data');

        $actual = array_keys($data);
        sort($actual);

        $expected = array_map(
            fn (SnsSettingKey $key): string => $key->value,
            SnsSettingKey::inGroup(SettingGroup::Base),
        );
        sort($expected);

        $this->assertSame($expected, $actual);
    }
}
