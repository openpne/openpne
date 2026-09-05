<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Features\Profile\ProfilePageVisibility;
use App\Features\Profile\ProfileVisibilityPolicy;
use App\Filament\Pages\MemberPrivacySettings;
use App\Models\AdminUser;
use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class MemberPrivacySettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(AdminUser::factory()->create(), 'admin');
    }

    public function test_enabling_web_public_age_takes_effect(): void
    {
        Livewire::test(MemberPrivacySettings::class)
            ->fillForm(['allow_web_public_age' => true])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sns_settings', ['key' => 'allow_web_public_age', 'value' => '1']);
    }

    public function test_saved_value_round_trips_into_the_form(): void
    {
        $this->setSnsSetting(SnsSettingKey::AllowWebPublicAge, true);

        Livewire::test(MemberPrivacySettings::class)
            ->assertSet('data.allow_web_public_age', true);
    }

    public function test_defaults_off_when_no_row_exists(): void
    {
        Livewire::test(MemberPrivacySettings::class)
            ->assertSet('data.allow_web_public_age', false);
    }

    public function test_the_profile_policy_mounts_as_its_stored_value_and_round_trips(): void
    {
        DB::table('sns_settings')->where('key', SnsSettingKey::ProfileVisibilityPolicy->value)->delete();
        app(SnsSettingService::class)->clearCache();

        // The service returns the typed enum and Filament's option cast folds it to the backing value; a field without that cast would need the fold done here.
        Livewire::test(MemberPrivacySettings::class)
            ->assertSet('data.profile_visibility_policy', 'members')
            ->fillForm(['profile_visibility_policy' => 'web'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sns_settings', ['key' => 'profile_visibility_policy', 'value' => 'web']);
        $this->assertSame(ProfileVisibilityPolicy::Web, ProfilePageVisibility::policy());

        Livewire::test(MemberPrivacySettings::class)->assertSet('data.profile_visibility_policy', 'web');
    }

    public function test_an_unknown_profile_policy_is_refused_before_the_save(): void
    {
        Livewire::test(MemberPrivacySettings::class)
            ->fillForm(['profile_visibility_policy' => 'everyone'])
            ->call('save')
            ->assertHasErrors(['data.profile_visibility_policy']);
    }
}
