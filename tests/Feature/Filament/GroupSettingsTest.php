<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Features\GroupTalk\GroupTalkNotifyDefault;
use App\Features\GroupTalk\GroupTalkNotifyMode;
use App\Filament\Pages\GroupSettings;
use App\Models\AdminUser;
use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The talk notification default. DB-authoritative; a fresh install with no row is mentions-only, so
 * the path that matters here is an administrator asking for every message.
 */
class GroupSettingsTest extends TestCase
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
        Livewire::test(GroupSettings::class)
            ->assertSet('data.group_talk_notify_default', GroupTalkNotifyMode::Mentions->value);
    }

    public function test_asking_for_every_message_takes_effect(): void
    {
        Livewire::test(GroupSettings::class)
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

        Livewire::test(GroupSettings::class)
            ->assertSet('data.group_talk_notify_default', GroupTalkNotifyMode::All->value);
    }

    public function test_switching_back_to_mentions_takes_effect(): void
    {
        $this->setSnsSetting(SnsSettingKey::GroupTalkNotifyDefault, GroupTalkNotifyMode::All->value);

        Livewire::test(GroupSettings::class)
            ->fillForm(['group_talk_notify_default' => GroupTalkNotifyMode::Mentions->value])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(GroupTalkNotifyMode::Mentions, app(GroupTalkNotifyDefault::class)->mode());
    }

    public function test_board_reply_links_are_off_until_switched_on(): void
    {
        Livewire::test(GroupSettings::class)
            ->assertSet('data.group_topic_comment_reply', false)
            ->assertSet('data.group_event_comment_reply', false)
            ->fillForm(['group_topic_comment_reply' => true, 'group_event_comment_reply' => true])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sns_settings', ['key' => 'group_topic_comment_reply', 'value' => '1']);
        $this->assertDatabaseHas('sns_settings', ['key' => 'group_event_comment_reply', 'value' => '1']);
        $this->assertTrue((bool) app(SnsSettingService::class)->get(SnsSettingKey::GroupTopicCommentReply));
    }
}
