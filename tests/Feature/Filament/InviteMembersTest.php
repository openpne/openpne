<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Features\Auth\RegistrationTokenSource;
use App\Filament\Pages\InviteMembers;
use App\Models\AdminUser;
use App\Models\Member;
use App\Notifications\Auth\RegistrationLinkNotification;
use App\Support\SnsSettingKey;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The admin batch-invite page (OpenPNE 3 admin member/invite): a textarea of addresses, each issued an
 * admin-invite registration token and mailed a link. Available unless registration is suspended.
 */
class InviteMembersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(AdminUser::factory()->create(), 'admin');
    }

    public function test_it_issues_admin_invite_tokens_for_valid_new_addresses(): void
    {
        Notification::fake();
        Member::factory()->create(['email' => 'taken@example.com']);

        Livewire::test(InviteMembers::class)
            ->fillForm(['emails' => "alice@example.com\nBob@example.com\ntaken@example.com\nnot-an-email"])
            ->call('send')
            ->assertHasNoErrors();

        // alice + bob issued, taken skipped (already a member), the garbage line ignored.
        $this->assertDatabaseCount('registration_tokens', 2);
        $this->assertDatabaseHas('registration_tokens', [
            'email' => 'alice@example.com',
            'source' => RegistrationTokenSource::AdminInvite->value,
            'inviter_id' => null,
        ]);
        $this->assertDatabaseHas('registration_tokens', ['email' => 'bob@example.com']);
        $this->assertDatabaseMissing('registration_tokens', ['email' => 'taken@example.com']);

        Notification::assertSentOnDemand(
            RegistrationLinkNotification::class,
            fn (RegistrationLinkNotification $n): bool => $n->source === RegistrationTokenSource::AdminInvite
        );
    }

    public function test_duplicate_lines_issue_a_single_token(): void
    {
        Notification::fake();

        Livewire::test(InviteMembers::class)
            ->fillForm(['emails' => "dup@example.com\nDUP@example.com"])
            ->call('send')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('registration_tokens', 1);
    }

    public function test_the_page_is_available_in_admin_only_mode_but_not_when_closed(): void
    {
        $this->setSnsSetting(SnsSettingKey::RegistrationMode, 'admin_only');
        $this->assertTrue(InviteMembers::canAccess());

        $this->setSnsSetting(SnsSettingKey::RegistrationMode, 'closed');
        $this->assertFalse(InviteMembers::canAccess());
    }
}
