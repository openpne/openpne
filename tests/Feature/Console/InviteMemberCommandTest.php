<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Features\Auth\Actions\IssueRegistrationToken;
use App\Features\Auth\RegistrationTokenSource;
use App\Mail\Template\MailUrlMapper;
use App\Models\Member;
use App\Models\RegistrationToken;
use App\Notifications\Auth\RegistrationLinkNotification;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CapturesSecurityLog;
use Tests\TestCase;

class InviteMemberCommandTest extends TestCase
{
    use CapturesSecurityLog;
    use RefreshDatabase;

    private const FORM = ['name' => 'Newcomer', 'password' => 'sufficiently-long-pw', 'password_confirmation' => 'sufficiently-long-pw'];

    private function invite(string $email = 'newcomer@example.com'): string
    {
        $status = Artisan::call('openpne:member:invite', ['email' => $email]);
        $output = Artisan::output();
        $this->assertSame(0, $status, $output);
        $this->assertSame(1, preg_match('#^\S+/register/[A-Za-z0-9]{40}$#m', $output, $match), $output);

        return $match[0];
    }

    private function mode(string $mode): void
    {
        $this->setSnsSetting(SnsSettingKey::RegistrationMode, $mode);
    }

    public function test_the_printed_link_registers_the_address_without_any_mail_at_issue(): void
    {
        Notification::fake();

        $link = $this->invite('Newcomer@Example.com');

        Notification::assertNothingSent();
        $pending = RegistrationToken::sole();
        $this->assertSame('newcomer@example.com', $pending->email);
        $this->assertSame(RegistrationTokenSource::AdminInvite, $pending->source);
        $this->assertNull($pending->inviter_id);

        $this->get($link)->assertOk()->assertSee('newcomer@example.com');
        $this->post($link, self::FORM)->assertRedirect('/');
        $this->assertTrue(Member::where('email', 'newcomer@example.com')->exists());
        $this->assertDatabaseCount('registration_tokens', 0);
    }

    public function test_a_unicode_space_around_the_address_is_trimmed(): void
    {
        Notification::fake();

        $this->invite("\u{3000}Padded@Example.com\u{00A0}");

        $this->assertSame('padded@example.com', RegistrationToken::sole()->email);
    }

    public function test_the_link_is_the_one_a_mail_for_the_same_token_would_carry(): void
    {
        Notification::fake();
        app(IssueRegistrationToken::class)('mailed@example.com', RegistrationTokenSource::AdminInvite);
        $mailed = null;
        Notification::assertSentOnDemand(RegistrationLinkNotification::class, function (RegistrationLinkNotification $n) use (&$mailed): bool {
            $mailed = $n;

            return true;
        });
        $rendered = (string) $mailed->toMail(new AnonymousNotifiable)->render();

        $this->assertStringContainsString(MailUrlMapper::resolve('member/register?token='.$mailed->rawToken), $rendered);

        $link = $this->invite('printed@example.com');
        $raw = substr($link, -40);
        $this->assertSame(MailUrlMapper::resolve('member/register?token='.$raw), $link);
        $this->assertSame(hash('sha256', $raw), RegistrationToken::where('email', 'printed@example.com')->value('token'));
    }

    public function test_a_closed_site_issues_nothing(): void
    {
        $this->mode('closed');

        $this->artisan('openpne:member:invite', ['email' => 'newcomer@example.com'])
            ->expectsOutputToContain('Registration is closed')
            ->assertFailed();

        $this->assertDatabaseCount('registration_tokens', 0);
    }

    public function test_a_link_stops_working_when_the_site_closes_and_works_on_an_admin_only_site(): void
    {
        $link = $this->invite();

        $this->mode('closed');
        $this->get($link)->assertNotFound();
        $this->post($link, self::FORM)->assertNotFound();
        $this->assertDatabaseCount('members', 0);

        $this->mode('admin_only');
        $this->post($link, self::FORM)->assertRedirect('/');
        $this->assertDatabaseCount('members', 1);
    }

    public function test_an_address_a_member_already_uses_is_refused_and_logs_nothing(): void
    {
        $this->captureSecurityLog();
        Member::factory()->create(['email' => 'taken@example.com']);

        $this->artisan('openpne:member:invite', ['email' => 'Taken@Example.com'])
            ->expectsOutputToContain('A member already uses [taken@example.com].')
            ->assertFailed();

        $this->assertDatabaseCount('registration_tokens', 0);
        $this->assertSame([], $this->securityRecords());
    }

    public function test_it_says_how_long_the_link_lives_and_where_it_points(): void
    {
        config(['openpne.registration.token_ttl_minutes' => 90]);

        $this->artisan('openpne:member:invite', ['email' => 'newcomer@example.com'])
            ->expectsOutputToContain('for 1 hour 30 minutes')
            ->expectsOutputToContain('starts with '.url('/').',')
            ->assertSuccessful();
    }

    public function test_something_that_is_not_an_address_is_refused(): void
    {
        $this->artisan('openpne:member:invite', ['email' => 'not-an-address'])
            ->expectsOutputToContain('is not an email address')
            ->assertFailed();

        $this->assertDatabaseCount('registration_tokens', 0);
    }

    public function test_a_full_site_refuses_a_new_address_but_reissues_for_one_holding_a_link(): void
    {
        $this->setSnsSetting(SnsSettingKey::MemberCapacity, 2);
        Member::factory()->create();
        $first = $this->invite('invited@example.com');

        $this->artisan('openpne:member:invite', ['email' => 'another@example.com'])
            ->expectsOutputToContain('member capacity')
            ->assertFailed();
        $this->assertDatabaseCount('registration_tokens', 1);

        $second = $this->invite('invited@example.com');
        $this->assertNotSame($first, $second);
        $this->get($first)->assertRedirect();
        $this->get($second)->assertOk();
    }

    public function test_a_printed_link_and_a_mailed_one_replace_each_other(): void
    {
        Notification::fake();
        $printed = $this->invite();

        app(IssueRegistrationToken::class)('newcomer@example.com', RegistrationTokenSource::AdminInvite);
        $mailed = null;
        Notification::assertSentOnDemand(RegistrationLinkNotification::class, function (RegistrationLinkNotification $n) use (&$mailed): bool {
            $mailed = url('/register/'.$n->rawToken);

            return true;
        });
        $this->get($printed)->assertRedirect();
        $this->get($mailed)->assertOk();

        $printedAgain = $this->invite();
        $this->get($mailed)->assertRedirect();
        $this->get($printedAgain)->assertOk();
        $this->assertDatabaseCount('registration_tokens', 1);
    }

    public function test_it_logs_the_issue_via_cli_without_the_token(): void
    {
        $this->captureSecurityLog();

        $link = $this->invite();

        $context = $this->assertOneSecurityEvent('registration.link_printed');
        $this->assertSame(['email' => 'newcomer@example.com', 'via' => 'cli'], $context);
        $this->assertStringNotContainsString(substr($link, -40), json_encode($this->securityRecords(), JSON_THROW_ON_ERROR));
    }

    public function test_a_closed_site_logs_nothing(): void
    {
        $this->captureSecurityLog();
        $this->mode('closed');

        $this->artisan('openpne:member:invite', ['email' => 'newcomer@example.com'])->assertFailed();

        $this->assertSame([], $this->securityRecords());
    }
}
