<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Actions\Fortify\CreateNewMember;
use App\Features\Auth\Actions\IssueRegistrationToken;
use App\Features\Auth\RegistrationTokenSource;
use App\Features\Member\Actions\RequestEmailChange;
use App\Filament\Pages\InviteMembers;
use App\Models\EmailChangeRequest;
use App\Models\Member;
use App\Models\RegistrationToken;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Support\SnsSettingKey;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NewEmailAddressTest extends TestCase
{
    use RefreshDatabase;

    private const PUNYCODE = 'user@xn--n8jyh.jp';

    private const ASCII_MESSAGE = 'The email address may contain only ASCII letters, digits and symbols. A domain with other characters can be entered in its punycode form (xn--…).';

    /** @return array<string, array{string}> */
    public static function nonAscii(): array
    {
        return ['Japanese domain' => ['user@れい.jp'], 'Japanese local part' => ['たろう@example.com']];
    }

    /** Stored the way an upgrade carries it, past the checks a new address goes through. */
    private function legacyMember(string $email): Member
    {
        $member = Member::factory()->create();
        DB::table('members')->where('id', $member->getKey())->update(['email' => $email]);

        return $member->refresh();
    }

    private function pendingChange(Member $member, string $newEmail): string
    {
        $raw = Str::random(40);
        EmailChangeRequest::create([
            'member_id' => $member->getKey(), 'new_email' => $newEmail,
            'token' => hash('sha256', $raw), 'created_at' => now(),
        ]);

        return $raw;
    }

    #[DataProvider('nonAscii')]
    public function test_self_registration_refuses_a_non_ascii_address(string $email): void
    {
        Notification::fake();
        $this->get('/register');
        $this->travel(3)->seconds();

        $this->post('/register', ['email' => $email])->assertSessionHasErrors(['email' => self::ASCII_MESSAGE]);

        $this->assertDatabaseCount('registration_tokens', 0);
    }

    public function test_self_registration_takes_a_punycode_domain(): void
    {
        Notification::fake();
        $this->get('/register');
        $this->travel(3)->seconds();

        $this->post('/register', ['email' => self::PUNYCODE])->assertRedirect(route('register.sent'));

        $this->assertDatabaseHas('registration_tokens', ['email' => self::PUNYCODE]);
    }

    public function test_a_malformed_ascii_address_keeps_the_generic_message(): void
    {
        $this->get('/register');
        $this->travel(3)->seconds();

        $this->post('/register', ['email' => 'not-an-email'])->assertSessionHasErrors(['email' => __('validation.email', ['attribute' => 'email'])]);
    }

    #[DataProvider('nonAscii')]
    public function test_a_member_invite_refuses_a_non_ascii_address(string $email): void
    {
        Notification::fake();
        $this->setSnsSetting(SnsSettingKey::RegistrationMode, 'invite');

        $this->actingAs(Member::factory()->create())->post('/invite', ['email' => $email])->assertSessionHasErrors('email');

        $this->assertDatabaseCount('registration_tokens', 0);
    }

    public function test_a_member_invite_takes_a_punycode_domain(): void
    {
        Notification::fake();
        $this->setSnsSetting(SnsSettingKey::RegistrationMode, 'invite');

        $this->actingAs(Member::factory()->create())->post('/invite', ['email' => self::PUNYCODE])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('registration_tokens', ['email' => self::PUNYCODE]);
    }

    public function test_an_email_change_takes_a_punycode_domain(): void
    {
        Notification::fake();
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/member/config/email', ['current_password' => 'password', 'new_email' => self::PUNYCODE])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('email_change_requests', ['member_id' => $member->getKey(), 'new_email' => self::PUNYCODE]);
    }

    public function test_the_invite_command_and_member_creation_take_a_punycode_domain(): void
    {
        $this->assertSame(0, Artisan::call('openpne:member:invite', ['email' => self::PUNYCODE]), Artisan::output());
        $this->assertDatabaseHas('registration_tokens', ['email' => self::PUNYCODE]);

        $member = app(CreateNewMember::class)->create(['email' => 'other@xn--n8jyh.jp', 'name' => 'Taro', 'password' => 'sufficiently-long-pw', 'password_confirmation' => 'sufficiently-long-pw']);
        $this->assertSame('other@xn--n8jyh.jp', $member->email);
    }

    #[DataProvider('nonAscii')]
    public function test_an_email_change_refuses_a_non_ascii_address(string $email): void
    {
        Notification::fake();
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/member/config/email', ['current_password' => 'password', 'new_email' => $email])
            ->assertSessionHasErrors('new_email');

        $this->assertDatabaseCount('email_change_requests', 0);
    }

    public function test_the_admin_invitation_page_skips_a_non_ascii_line(): void
    {
        Notification::fake();
        Filament::setCurrentPanel('admin');
        $this->actingAsAdmin();

        Livewire::test(InviteMembers::class)
            ->fillForm(['emails' => "user@れい.jp\nたろう@example.com\n".self::PUNYCODE])
            ->call('send')
            ->assertHasNoErrors();

        $this->assertSame([self::PUNYCODE], RegistrationToken::pluck('email')->all());
    }

    #[DataProvider('nonAscii')]
    public function test_the_invite_command_refuses_a_non_ascii_address(string $email): void
    {
        $status = Artisan::call('openpne:member:invite', ['email' => $email]);

        $this->assertNotSame(0, $status);
        $this->assertStringContainsString("[{$email}] is not an email address.", Artisan::output());
        $this->assertDatabaseCount('registration_tokens', 0);
    }

    public function test_creating_a_member_refuses_a_non_ascii_address(): void
    {
        $this->expectException(ValidationException::class);

        app(CreateNewMember::class)->create(['email' => 'たろう@example.com', 'name' => 'Taro', 'password' => 'sufficiently-long-pw', 'password_confirmation' => 'sufficiently-long-pw']);
    }

    public function test_issuing_a_link_for_a_non_ascii_address_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        try {
            app(IssueRegistrationToken::class)->unmailedLink('たろう@example.com', RegistrationTokenSource::AdminInvite);
        } finally {
            $this->assertDatabaseCount('registration_tokens', 0);
        }
    }

    public function test_requesting_a_change_to_a_non_ascii_address_throws(): void
    {
        Notification::fake();
        $this->expectException(InvalidArgumentException::class);

        try {
            app(RequestEmailChange::class)(Member::factory()->create(), 'たろう@example.com');
        } finally {
            $this->assertDatabaseCount('email_change_requests', 0);
            Notification::assertNothingSent();
        }
    }

    public function test_saving_a_member_with_a_non_ascii_address_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Member::factory()->create(['email' => 'たろう@example.com']);
    }

    public function test_a_member_with_a_stored_non_ascii_address_saves_other_changes(): void
    {
        $member = $this->legacyMember('たろう@example.com');

        $member->forceFill(['name' => 'Renamed'])->save();

        $this->assertSame('Renamed', $member->fresh()->name);
    }

    public function test_a_pending_registration_for_a_non_ascii_address_reads_as_expired(): void
    {
        $raw = Str::random(40);
        DB::table('registration_tokens')->insert(['email' => 'たろう@example.com', 'token' => hash('sha256', $raw), 'created_at' => now()]);
        $form = ['name' => 'Taro', 'password' => 'sufficiently-long-pw', 'password_confirmation' => 'sufficiently-long-pw'];

        $this->get("/register/{$raw}")->assertRedirect(route('register'))->assertSessionHas('status');
        $this->post("/register/{$raw}", $form)->assertRedirect(route('register'))->assertSessionHas('status');

        $this->assertDatabaseCount('members', 0);
    }

    public function test_a_pending_change_to_a_non_ascii_address_is_voided_on_confirm(): void
    {
        $member = Member::factory()->create();
        $email = $member->email;
        $raw = Str::random(40);
        DB::table('email_change_requests')->insert(['member_id' => $member->getKey(), 'new_email' => 'たろう@example.com', 'token' => hash('sha256', $raw), 'created_at' => now()]);

        $this->actingAs($member)->get("/member/config/email/confirm/{$raw}")
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', __('This email-change link is no longer valid.'));
        $this->post("/member/config/email/confirm/{$raw}")
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', __('This email-change link is no longer valid.'));

        $this->assertSame($email, $member->fresh()->email);
        $this->assertDatabaseCount('email_change_requests', 0);
    }

    public function test_a_member_with_a_stored_non_ascii_address_signs_in_and_resets_the_password(): void
    {
        Notification::fake();
        $member = $this->legacyMember('たろう@example.com');

        $this->post('/login', ['email' => 'たろう@example.com', 'password' => 'password']);
        $this->assertAuthenticatedAs($member);

        auth('member')->logout();
        $this->post('/forgot-password', ['email' => 'たろう@example.com'])->assertSessionHasNoErrors();
        Notification::assertSentTo($member, ResetPasswordNotification::class);
    }

    public function test_a_stored_address_changes_to_the_ascii_spelling_it_compares_equal_to(): void
    {
        // On MySQL the two compare equal, so the in-use checks must leave the member's own row out.
        Notification::fake();
        $member = $this->legacyMember('ｕｓｅｒ@example.com');

        $this->actingAs($member)->post('/member/config/email', ['current_password' => 'password', 'new_email' => 'user@example.com'])
            ->assertSessionHasNoErrors();
        $raw = Str::random(40);
        EmailChangeRequest::where('member_id', $member->getKey())->update(['token' => hash('sha256', $raw)]);

        $this->post("/member/config/email/confirm/{$raw}")->assertRedirect(route('login'));

        $this->assertSame('user@example.com', $member->fresh()->email);
    }

    public function test_confirming_a_change_voids_the_reset_token_of_the_old_address(): void
    {
        // On MySQL the token stored under the full-width spelling would otherwise still answer for the new one.
        $member = $this->legacyMember('ｕｓｅｒ@example.com');
        $token = Password::broker(config('fortify.passwords'))->createToken($member);
        $raw = $this->pendingChange($member, 'user@example.com');

        $this->actingAs($member)->post("/member/config/email/confirm/{$raw}")->assertRedirect(route('login'));

        $this->assertSame(0, DB::table('password_reset_tokens')->count());
        $this->assertFalse(Password::broker(config('fortify.passwords'))->tokenExists($member->fresh(), $token));
    }

    public function test_a_reset_issued_for_the_member_as_read_before_the_change_mails_the_new_address(): void
    {
        // The broker's steps after its lookup, on a model read before the change committed; Notification::fake would skip the queue's re-read.
        $member = Member::factory()->create(['email' => 'old@example.com']);
        $stale = Member::findOrFail($member->getKey());
        $raw = $this->pendingChange($member, 'new@example.com');
        $this->actingAs($member)->post("/member/config/email/confirm/{$raw}")->assertRedirect(route('login'));
        $recipients = [];
        Event::listen(NotificationSending::class, function (NotificationSending $event) use (&$recipients): void {
            if ($event->notification instanceof ResetPasswordNotification) {
                $recipients[] = $event->notifiable->routeNotificationFor('mail', $event->notification);
            }
        });

        $stale->sendPasswordResetNotification(Password::broker(config('fortify.passwords'))->createToken($stale));

        $this->assertSame(['new@example.com'], $recipients);
    }
}
