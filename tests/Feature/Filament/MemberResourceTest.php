<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\Members\MemberResource;
use App\Filament\Resources\Members\Pages\ListMembers;
use App\Models\AdminUser;
use App\Models\Diary;
use App\Models\Member;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class MemberResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(AdminUser::factory()->create(), 'admin');

        // Reserve id 1 as the un-withdrawable primary member so factory subjects below get id >= 2.
        Member::factory()->create(['id' => 1]);
    }

    public function test_list_page_renders_members(): void
    {
        $members = Member::factory()->count(2)->create();

        Livewire::test(ListMembers::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords($members);
    }

    public function test_search_by_name_and_email(): void
    {
        $match = Member::factory()->create(['name' => 'Findme', 'email' => 'findme@example.test']);
        $other = Member::factory()->create(['name' => 'Unrelated', 'email' => 'nope@example.test']);

        Livewire::test(ListMembers::class)
            ->searchTable('Findme')
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$other]);

        Livewire::test(ListMembers::class)
            ->searchTable('findme@example.test')
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_ban_rejects_login(): void
    {
        $member = Member::factory()->create(['is_login_rejected' => false]);

        Livewire::test(ListMembers::class)
            ->callAction(TestAction::make('ban')->table($member));

        $member->refresh();
        $this->assertTrue($member->is_login_rejected);
    }

    public function test_ban_revokes_live_sessions_and_remember_tokens(): void
    {
        // The freeze flag only blocks the NEXT login; the ban must also end what already
        // exists — server-side sessions and remember-me cookies — immediately.
        config(['session.driver' => 'database']);
        $member = Member::factory()->create(['is_login_rejected' => false]);
        $member->forceFill(['remember_token' => Str::random(60)])->save();
        $before = $member->remember_token;

        DB::table('sessions')->insert([
            'id' => 'member-device', 'user_id' => $member->getKey(),
            'payload' => base64_encode('{}'), 'last_activity' => time(),
        ]);
        // The banning operator's own panel session lives in admin_sessions; even with a
        // colliding id it must survive a member revocation.
        DB::table('admin_sessions')->insert([
            'id' => 'operator-device', 'user_id' => $member->getKey(),
            'payload' => base64_encode('{}'), 'last_activity' => time(),
        ]);

        Livewire::test(ListMembers::class)
            ->callAction(TestAction::make('ban')->table($member));

        $this->assertDatabaseMissing('sessions', ['id' => 'member-device']);
        $this->assertDatabaseHas('admin_sessions', ['id' => 'operator-device']);
        $this->assertNotSame($before, $member->fresh()->remember_token);
    }

    public function test_unban_allows_login(): void
    {
        $member = Member::factory()->create(['is_login_rejected' => true]);

        Livewire::test(ListMembers::class)
            ->callAction(TestAction::make('unban')->table($member));

        $member->refresh();
        $this->assertFalse($member->is_login_rejected);
    }

    public function test_delete_withdraws_the_member_and_owned_content(): void
    {
        $member = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $member->getKey()]);

        Livewire::test(ListMembers::class)
            ->callAction(TestAction::make('delete')->table($member))
            ->assertNotified(__('filament-actions::delete.single.notifications.deleted.title'));

        $this->assertModelMissing($member);
        $this->assertModelMissing($diary);
    }

    public function test_primary_member_cannot_be_withdrawn(): void
    {
        $primary = Member::findOrFail(1);

        $this->assertFalse(MemberResource::canDelete($primary));

        // Neither withdrawal nor login-freeze is offered for the primary member (lockout guard).
        Livewire::test(ListMembers::class)
            ->assertActionHidden(TestAction::make('delete')->table($primary))
            ->assertActionHidden(TestAction::make('ban')->table($primary));
    }
}
