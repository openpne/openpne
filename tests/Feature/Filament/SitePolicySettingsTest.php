<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\SitePolicySettings;
use App\Models\AdminUser;
use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class SitePolicySettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('sns_settings')->truncate();
        app(SnsSettingService::class)->clearCache();

        Filament::setCurrentPanel('admin');
        $this->actingAs(AdminUser::factory()->create(), 'admin');
    }

    public function test_saving_stores_both_bodies_verbatim(): void
    {
        $terms = "  ## 第1条\n本規約は…\n";

        Livewire::test(SitePolicySettings::class)
            ->fillForm(['user_agreement' => $terms, 'privacy_policy' => '取得する情報'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sns_settings', ['key' => 'user_agreement', 'value' => $terms]);
        $this->assertDatabaseHas('sns_settings', ['key' => 'privacy_policy', 'value' => '取得する情報']);
    }

    public function test_the_form_shows_what_is_stored(): void
    {
        $this->setSnsSetting(SnsSettingKey::UserAgreement, '会員規約');

        Livewire::test(SitePolicySettings::class)
            ->assertFormSet(['user_agreement' => '会員規約']);
    }

    public function test_a_body_over_the_column_size_is_rejected(): void
    {
        // Multi-byte, so a character count would let it through and the column would truncate it.
        Livewire::test(SitePolicySettings::class)
            ->fillForm(['user_agreement' => str_repeat('あ', 30_000)])
            ->call('save')
            ->assertHasFormErrors(['user_agreement']);

        $this->assertDatabaseMissing('sns_settings', ['key' => 'user_agreement']);
    }
}
