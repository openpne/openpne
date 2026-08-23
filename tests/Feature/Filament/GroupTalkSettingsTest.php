<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Features\GroupTalk\GroupTalkNotifyDefault;
use App\Features\GroupTalk\GroupTalkNotifyMode;
use App\Filament\Pages\GroupTalkSettings;
use App\Models\AdminUser;
use App\Support\SnsSettingKey;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The talk notification default. DB-authoritative; a fresh install with no row is mentions-only, so
 * the path that matters here is an administrator asking for every message.
 */
class GroupTalkSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(AdminUser::factory()->create(), 'admin');
    }

    public function test_defaults_to_mentions_when_no_row_exists(): void
    {
        Livewire::test(GroupTalkSettings::class)
            ->assertSet('data.group_talk_notify_default', GroupTalkNotifyMode::Mentions->value);
    }

    public function test_asking_for_every_message_takes_effect(): void
    {
        Livewire::test(GroupTalkSettings::class)
            ->fillForm(['group_talk_notify_default' => GroupTalkNotifyMode::All->value])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sns_settings', ['key' => 'group_talk_notify_default', 'value' => 'all']);
        // The save clears the settings cache, so a service resolved afterwards reads the new mode.
        $this->assertSame(GroupTalkNotifyMode::All, app(GroupTalkNotifyDefault::class)->mode());
    }

    public function test_saved_value_round_trips_into_the_form(): void
    {
        $this->setSnsSetting(SnsSettingKey::GroupTalkNotifyDefault, GroupTalkNotifyMode::All->value);

        Livewire::test(GroupTalkSettings::class)
            ->assertSet('data.group_talk_notify_default', GroupTalkNotifyMode::All->value);
    }

    public function test_switching_back_to_mentions_takes_effect(): void
    {
        $this->setSnsSetting(SnsSettingKey::GroupTalkNotifyDefault, GroupTalkNotifyMode::All->value);

        Livewire::test(GroupTalkSettings::class)
            ->fillForm(['group_talk_notify_default' => GroupTalkNotifyMode::Mentions->value])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(GroupTalkNotifyMode::Mentions, app(GroupTalkNotifyDefault::class)->mode());
    }
}
