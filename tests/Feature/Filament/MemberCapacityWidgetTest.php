<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Widgets\MemberCapacityWidget;
use App\Models\AdminUser;
use App\Models\Member;
use App\Models\RegistrationToken;
use App\Support\SnsSettingKey;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MemberCapacityWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(AdminUser::factory()->create(), 'admin');
    }

    public function test_the_widget_is_absent_until_the_host_sets_a_cap(): void
    {
        $this->assertFalse(MemberCapacityWidget::canView());

        $this->setSnsSetting(SnsSettingKey::MemberCapacity, 10);
        $this->assertTrue(MemberCapacityWidget::canView());
    }

    public function test_the_member_stat_shows_seats_taken_invited_and_left(): void
    {
        $this->setSnsSetting(SnsSettingKey::MemberCapacity, 10);
        $members = Member::factory()->count(3)->create();
        Member::factory()->aiAccount($members->first())->create();
        RegistrationToken::create(['email' => 'invited@example.com', 'token' => hash('sha256', 'x'), 'created_at' => now()]);

        Livewire::test(MemberCapacityWidget::class)
            ->assertSuccessful()
            ->assertSee(__('Member capacity'))
            ->assertSee('3 / 10')
            ->assertSee(__(':count left', ['count' => 6]))
            ->assertSee(__(':count invited', ['count' => 1]))
            ->assertDontSee(__('AI account capacity'));
    }

    public function test_the_member_stat_says_full_and_over(): void
    {
        Member::factory()->count(3)->create();

        $this->setSnsSetting(SnsSettingKey::MemberCapacity, 3);
        Livewire::test(MemberCapacityWidget::class)->assertSee(__('Full'));

        $this->setSnsSetting(SnsSettingKey::MemberCapacity, 2);
        Livewire::test(MemberCapacityWidget::class)->assertSee(__('Over capacity'));
    }

    public function test_the_ai_stat_counts_ai_accounts_against_their_own_cap(): void
    {
        $this->setSnsSetting(SnsSettingKey::AiAccountCapacity, 2);
        Member::factory()->create();
        Member::factory()->aiAccount()->create();

        Livewire::test(MemberCapacityWidget::class)
            ->assertSuccessful()
            ->assertDontSee(__('Member capacity'))
            ->assertSee(__('AI account capacity'))
            ->assertSee('1 / 2')
            ->assertSee(__(':count left', ['count' => 1]));
    }

    public function test_the_dashboard_mounts_the_widget_only_under_a_cap(): void
    {
        // Stats widgets render lazily, so the page carries the component, not its numbers.
        $this->get('/admin')->assertOk()->assertDontSee('MemberCapacityWidget');

        $this->setSnsSetting(SnsSettingKey::MemberCapacity, 10);
        $this->get('/admin')->assertOk()->assertSee('MemberCapacityWidget');
    }
}
