<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Features\CommunityTopic\Actions\CreateTopicComment;
use App\Filament\Widgets\OverviewStatsWidget;
use App\Filament\Widgets\RecentMembersWidget;
use App\Filament\Widgets\RegistrationModeWidget;
use App\Models\AdminUser;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\CommunityTopic;
use App\Models\Diary;
use App\Models\Member;
use App\Support\SnsSettingKey;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardWidgetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(AdminUser::factory()->create(), 'admin');
    }

    public function test_dashboard_page_renders_for_admin(): void
    {
        $this->get('/admin')->assertOk();
    }

    public function test_overview_stats_render(): void
    {
        Member::factory()->count(3)->create();
        Diary::factory()->count(2)->create();
        Community::factory()->create();

        Livewire::test(OverviewStatsWidget::class)
            ->assertSuccessful()
            ->assertSee(__('Members'))
            ->assertSee(__('New members this month'))
            ->assertSee(__('Diaries this month'));
    }

    public function test_registration_mode_widget_reflects_current_mode(): void
    {
        $this->setSnsSetting(SnsSettingKey::RegistrationMode, 'invite');
        Livewire::test(RegistrationModeWidget::class)
            ->assertSuccessful()
            ->assertSee(__('Invite only'));

        // Closed is the state the widget exists to surface; it must render its own label.
        $this->setSnsSetting(SnsSettingKey::RegistrationMode, 'closed');
        Livewire::test(RegistrationModeWidget::class)
            ->assertSuccessful()
            ->assertSee(__('Registration closed'));
    }

    public function test_active_communities_count_includes_a_fresh_comment_on_an_old_topic(): void
    {
        $since = now()->subDays(30);

        // A community whose only topic is old, but just received a comment — must count as active
        // (the comment bumps the topic's updated_at).
        $active = Community::factory()->create();
        $oldTopic = CommunityTopic::factory()->create([
            'community_id' => $active->getKey(),
            'created_at' => now()->subYear(),
            'updated_at' => now()->subYear(),
        ]);
        $commenter = Member::factory()->create();
        CommunityMember::factory()->create([
            'community_id' => $active->getKey(),
            'member_id' => $commenter->getKey(),
        ]);
        app(CreateTopicComment::class)($commenter, $oldTopic, 'A fresh reply on an old thread.');

        // A community with only an old, untouched topic — must not count.
        $stale = Community::factory()->create();
        CommunityTopic::factory()->create([
            'community_id' => $stale->getKey(),
            'created_at' => now()->subYear(),
            'updated_at' => now()->subYear(),
        ]);

        $this->assertSame(1, OverviewStatsWidget::activeCommunityCount($since));
    }

    public function test_recent_members_shows_latest_capped_at_ten(): void
    {
        $recent = Member::factory()->count(10)->create();
        $oldest = Member::factory()->create(['created_at' => now()->subYear()]);

        Livewire::test(RecentMembersWidget::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords($recent)
            ->assertCanNotSeeTableRecords([$oldest]);
    }
}
