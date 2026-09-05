<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Features\Timeline\TimelinePosting;
use App\Filament\Pages\TimelineSettings;
use App\Models\AdminUser;
use App\Support\SnsSettingKey;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TimelineSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(AdminUser::factory()->create(), 'admin');
    }

    public function test_enabling_web_public_posts_takes_effect(): void
    {
        Livewire::test(TimelineSettings::class)
            ->fillForm(['timeline_allow_web_public' => true])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sns_settings', ['key' => 'timeline_allow_web_public', 'value' => '1']);
    }

    public function test_saved_value_round_trips_into_the_form(): void
    {
        $this->setSnsSetting(SnsSettingKey::TimelineAllowWebPublic, true);

        Livewire::test(TimelineSettings::class)
            ->assertSet('data.timeline_allow_web_public', true);
    }

    public function test_closing_posting_takes_effect_and_round_trips(): void
    {
        Livewire::test(TimelineSettings::class)
            ->assertSet('data.timeline_posting_enabled', true)
            ->fillForm(['timeline_posting_enabled' => false])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sns_settings', ['key' => 'timeline_posting_enabled', 'value' => '0']);
        $this->assertFalse(TimelinePosting::enabled());

        Livewire::test(TimelineSettings::class)->assertSet('data.timeline_posting_enabled', false);
    }

    public function test_defaults_off_when_no_row_exists(): void
    {
        Livewire::test(TimelineSettings::class)
            ->assertSet('data.timeline_allow_web_public', false);
    }
}
