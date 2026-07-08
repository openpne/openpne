<?php

declare(strict_types=1);

namespace Tests\Feature\Auth\Listeners;

use App\Features\Auth\Actions\CompleteRegistration;
use App\Features\Auth\Events\MemberRegistered;
use App\Listeners\Auth\NotifyMemberRegistered;
use App\Models\Member;
use App\Models\RegistrationToken;
use App\Notifications\Member\RegistrationCompletedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotifyMemberRegisteredTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_listener_mails_the_new_member(): void
    {
        Notification::fake();
        $member = Member::factory()->create(['locale' => 'ja']);

        app(NotifyMemberRegistered::class)->handle(new MemberRegistered($member));

        Notification::assertSentTo(
            $member,
            RegistrationCompletedNotification::class,
            fn (RegistrationCompletedNotification $notification, array $channels): bool => $channels === ['mail'],
        );
    }

    public function test_completing_registration_dispatches_the_mail_through_auto_discovery(): void
    {
        Notification::fake();
        $raw = Str::random(40);
        RegistrationToken::create(['email' => 'newcomer@example.com', 'token' => hash('sha256', $raw), 'created_at' => now()]);
        $pending = RegistrationToken::where('token', hash('sha256', $raw))->firstOrFail();

        $member = app(CompleteRegistration::class)($pending, [
            'name' => 'Newcomer',
            'password' => 'sufficiently-long-pw',
            'password_confirmation' => 'sufficiently-long-pw',
        ]);

        Notification::assertSentTo($member, RegistrationCompletedNotification::class);
    }
}
