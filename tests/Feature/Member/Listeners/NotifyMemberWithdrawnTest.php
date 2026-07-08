<?php

declare(strict_types=1);

namespace Tests\Feature\Member\Listeners;

use App\Features\Member\Actions\WithdrawMember;
use App\Features\Member\Events\MemberWithdrawn;
use App\Listeners\Member\NotifyMemberWithdrawn;
use App\Models\Member;
use App\Notifications\Member\WithdrawalAdminNotification;
use App\Notifications\Member\WithdrawalCompletedNotification;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotifyMemberWithdrawnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Reserve id 1 as the un-withdrawable primary member so factory subjects get id >= 2.
        Member::factory()->create(['id' => 1]);
    }

    public function test_the_listener_mails_the_former_member_and_the_admin(): void
    {
        Notification::fake();
        $this->setSnsSetting(SnsSettingKey::AdminMailAddress, 'ops@example.test');

        app(NotifyMemberWithdrawn::class)->handle(
            new MemberWithdrawn(42, 'Gone', 'gone@example.test', 'ja'),
        );

        Notification::assertSentOnDemand(
            WithdrawalCompletedNotification::class,
            fn ($notification, array $channels, $notifiable): bool => ($notifiable->routes['mail'] ?? null) === 'gone@example.test'
                && $notification->memberName === 'Gone',
        );
        Notification::assertSentOnDemand(
            WithdrawalAdminNotification::class,
            fn ($notification, array $channels, $notifiable): bool => ($notifiable->routes['mail'] ?? null) === 'ops@example.test'
                && $notification->memberEmail === 'gone@example.test',
        );
    }

    public function test_a_member_without_an_address_gets_no_receipt_but_the_admin_is_notified(): void
    {
        Notification::fake();
        $this->setSnsSetting(SnsSettingKey::AdminMailAddress, 'ops@example.test');

        // A login-impossible member upgraded from OpenPNE 3 has no address (captured as '').
        app(NotifyMemberWithdrawn::class)->handle(new MemberWithdrawn(42, 'Gone', '', 'ja'));

        // Only the admin notice — the empty-address receipt is skipped.
        Notification::assertCount(1);
        Notification::assertSentOnDemand(
            WithdrawalAdminNotification::class,
            fn ($notification, array $channels, $notifiable): bool => ($notifiable->routes['mail'] ?? null) === 'ops@example.test',
        );
    }

    public function test_withdrawing_a_member_without_an_address_yields_an_empty_email_payload(): void
    {
        Event::fake([MemberWithdrawn::class]);
        $member = Member::factory()->create(['email' => null]);

        app(WithdrawMember::class)($member);

        Event::assertDispatched(
            MemberWithdrawn::class,
            fn (MemberWithdrawn $event): bool => $event->email === '' && $event->memberId === (int) $member->getKey(),
        );
    }

    public function test_withdrawing_dispatches_the_event_with_a_scalar_payload_after_delete(): void
    {
        Event::fake([MemberWithdrawn::class]);
        $member = Member::factory()->create(['name' => 'Leaver', 'email' => 'leaver@example.test', 'locale' => 'ja']);

        app(WithdrawMember::class)($member);

        // Scalars captured pre-delete, and the row is already gone when the event carries them.
        $this->assertModelMissing($member);
        Event::assertDispatched(
            MemberWithdrawn::class,
            fn (MemberWithdrawn $event): bool => $event->memberId === (int) $member->getKey()
                && $event->name === 'Leaver'
                && $event->email === 'leaver@example.test'
                && $event->locale === 'ja',
        );
    }

    public function test_a_member_without_a_stored_locale_falls_back_to_the_site_default(): void
    {
        Event::fake([MemberWithdrawn::class]);
        config()->set('app.locale', 'en');
        $member = Member::factory()->create(['locale' => null]);

        app(WithdrawMember::class)($member);

        Event::assertDispatched(
            MemberWithdrawn::class,
            fn (MemberWithdrawn $event): bool => $event->locale === 'en',
        );
    }
}
