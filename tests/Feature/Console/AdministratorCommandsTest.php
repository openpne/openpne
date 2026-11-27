<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Features\Auth\RegistrationTokenSource;
use App\Models\AdminRoleInvite;
use App\Models\Member;
use App\Models\RegistrationToken;
use App\Support\Housekeeping;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use RuntimeException;
use Tests\Concerns\CapturesSecurityLog;
use Tests\Concerns\SeparatesCommandStreams;
use Tests\TestCase;

class AdministratorCommandsTest extends TestCase
{
    use CapturesSecurityLog, RefreshDatabase, SeparatesCommandStreams;

    private const FORM = ['name' => 'Newcomer', 'password' => 'sufficiently-long-pw', 'password_confirmation' => 'sufficiently-long-pw'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->captureSecurityLog();
        Carbon::setTestNow(Carbon::parse('2026-12-01 09:30:00', 'UTC'));
    }

    public function test_invite_prints_only_the_link_and_writes_the_invite_with_the_link(): void
    {
        [$exit, $stdout, $stderr] = $this->runSeparated('openpne:admin:invite', ['email' => ' Admin@Example.com ']);

        $this->assertSame(0, $exit, $stderr);
        $this->assertMatchesRegularExpression('#^\S+/register/[A-Za-z0-9]{40}\n$#', $stdout);
        $this->assertStringContainsString('[admin@example.com]', $stderr);

        $token = RegistrationToken::sole();
        $this->assertSame(RegistrationTokenSource::AdminRoleInvite, $token->source);
        $this->assertSame('admin@example.com', $token->email);
        $invite = AdminRoleInvite::sole();
        $this->assertSame('admin@example.com', $invite->email);
        $this->assertSame(now()->addMinutes((int) config('openpne.registration.token_ttl_minutes'))->getTimestamp(), $invite->expires_at);

        $context = $this->assertOneSecurityEvent('registration.link_printed');
        $this->assertSame(['admin@example.com', 'cli', 'admin'], [$context['email'], $context['via'], $context['role']]);
    }

    public function test_invite_json_is_one_line_with_email_url_and_expiry(): void
    {
        [$exit, $stdout, $stderr] = $this->runSeparated('openpne:admin:invite', ['email' => 'admin@example.com', '--json' => true]);

        $this->assertSame(0, $exit, $stderr);
        $this->assertSame('', $stderr);
        $this->assertSame(1, substr_count($stdout, "\n"));
        $json = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['email', 'url', 'expires_at'], array_keys($json));
        $this->assertSame('admin@example.com', $json['email']);
        $this->assertMatchesRegularExpression('#/register/[A-Za-z0-9]{40}$#', $json['url']);
        $this->assertSame('2026-12-02T09:30:00Z', $json['expires_at']);
    }

    public function test_the_invites_link_registers_an_administrator_on_a_closed_site_who_lands_on_the_panel(): void
    {
        $this->setSnsSetting(SnsSettingKey::RegistrationMode, 'closed');

        [, $stdout] = $this->runSeparated('openpne:admin:invite', ['email' => 'admin@example.com']);
        $path = (string) parse_url(trim($stdout), PHP_URL_PATH);
        // An intended URL left from an earlier visit must not take the new administrator elsewhere.
        $this->withSession(['url.intended' => url('/diary')])->post($path, self::FORM)->assertRedirect(url('/admin'));

        $this->assertTrue(Member::where('email', 'admin@example.com')->sole()->isAdministrator());
        $this->get('/admin')->assertOk();
    }

    public function test_inviting_again_replaces_the_link_and_extends_the_invite(): void
    {
        $this->setSnsSetting(SnsSettingKey::RegistrationMode, 'invite');
        [, $first] = $this->runSeparated('openpne:admin:invite', ['email' => 'admin@example.com']);
        $this->travel(2)->hours();
        [, $second] = $this->runSeparated('openpne:admin:invite', ['email' => 'admin@example.com']);

        $this->assertNotSame($first, $second);
        $this->assertSame(1, AdminRoleInvite::count());
        $this->assertSame(now()->addMinutes((int) config('openpne.registration.token_ttl_minutes'))->getTimestamp(), AdminRoleInvite::sole()->expires_at);
        $this->get(parse_url(trim($first), PHP_URL_PATH))->assertRedirect(route('login'));
    }

    public function test_invite_refuses_an_existing_member_and_a_full_site_and_writes_nothing(): void
    {
        Member::factory()->create(['email' => 'member@example.com']);

        [$exit, $stdout, $stderr] = $this->runSeparated('openpne:admin:invite', ['email' => 'member@example.com']);
        $this->assertSame(1, $exit);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('openpne:admin:grant', $stderr);

        $this->setSnsSetting(SnsSettingKey::MemberCapacity, 1);
        [$exit, $stdout, $stderr] = $this->runSeparated('openpne:admin:invite', ['email' => 'admin@example.com', '--json' => true]);
        $this->assertSame(1, $exit);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('capacity', $stderr);

        [$exit, $stdout] = $this->runSeparated('openpne:admin:invite', ['email' => 'not an address']);
        $this->assertSame(1, $exit);
        $this->assertSame('', $stdout);

        $this->assertSame(0, AdminRoleInvite::count());
        $this->assertSame(0, RegistrationToken::count());
        $this->assertCount(0, $this->securityRecords('registration.link_printed'));
    }

    public function test_an_invite_that_fails_to_write_leaves_no_link(): void
    {
        DB::beforeExecuting(function (string $sql): void {
            if (preg_match('/insert into [`"]admin_role_invites[`"]/', $sql)) {
                throw new RuntimeException('the invite was not written');
            }
        });

        [$exit, $stdout] = $this->runSeparated('openpne:admin:invite', ['email' => 'admin@example.com']);

        $this->assertNotSame(0, $exit);
        $this->assertSame('', $stdout);
        $this->assertDatabaseCount('registration_tokens', 0);
    }

    public function test_grant_and_revoke_take_an_id_or_an_address(): void
    {
        $first = Member::factory()->create(['email' => 'first@example.com']);
        $second = Member::factory()->create(['email' => 'second@example.com']);

        $this->assertSame(0, $this->runSeparated('openpne:admin:grant', ['member' => (string) $first->id])[0]);
        $this->assertSame(0, $this->runSeparated('openpne:admin:grant', ['member' => 'Second@Example.com'])[0]);
        $this->assertTrue($first->fresh()->isAdministrator());
        $this->assertTrue($second->fresh()->isAdministrator());

        $this->assertSame(0, $this->runSeparated('openpne:admin:revoke', ['member' => 'second@example.com'])[0]);
        $this->assertFalse($second->fresh()->isAdministrator());
        $this->assertSame('cli', $this->assertOneSecurityEvent('admin.revoked')['actor']);
    }

    public function test_a_repeated_grant_exits_zero_and_changes_nothing(): void
    {
        $member = Member::factory()->create();
        $this->runSeparated('openpne:admin:grant', ['member' => (string) $member->id]);

        [$exit, $stdout] = $this->runSeparated('openpne:admin:grant', ['member' => (string) $member->id]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('already', $stdout);
        $this->assertSame(1, $member->fresh()->admin_generation);
        $this->assertCount(1, $this->securityRecords('admin.granted'));
    }

    public function test_grant_refusals_exit_non_zero_on_stderr(): void
    {
        $owner = Member::factory()->create();
        $refused = [
            (string) Member::factory()->aiAccount($owner)->create()->id,
            (string) Member::factory()->create(['is_login_rejected' => true])->id,
            'nobody@example.com',
            '999999',
        ];
        $withdrawing = Member::factory()->create();
        $withdrawing->forceFill(['withdrawal_started_at' => now()])->save();
        $refused[] = (string) $withdrawing->id;

        foreach ($refused as $argument) {
            [$exit, $stdout, $stderr] = $this->runSeparated('openpne:admin:grant', ['member' => $argument]);
            $this->assertSame(1, $exit, $argument);
            $this->assertSame('', $stdout, $argument);
            $this->assertNotSame('', $stderr, $argument);
        }

        $this->assertSame(0, Member::whereNotNull('admin_granted_at')->count());
    }

    public function test_revoke_refuses_the_last_administrator(): void
    {
        $member = Member::factory()->create();
        $this->runSeparated('openpne:admin:grant', ['member' => (string) $member->id]);

        [$exit, $stdout, $stderr] = $this->runSeparated('openpne:admin:revoke', ['member' => (string) $member->id]);

        $this->assertSame(1, $exit);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('last administrator', $stderr);
        $this->assertTrue($member->fresh()->isAdministrator());
    }

    public function test_revoking_someone_without_the_role_exits_zero(): void
    {
        $member = Member::factory()->create();

        $this->assertSame(0, $this->runSeparated('openpne:admin:revoke', ['member' => (string) $member->id])[0]);
        $this->assertSame(1, $this->runSeparated('openpne:admin:revoke', ['member' => '999999'])[0]);
    }

    public function test_an_expired_invite_is_kept_seven_days_then_swept(): void
    {
        $this->runSeparated('openpne:admin:invite', ['email' => 'admin@example.com']);
        $expiry = AdminRoleInvite::sole()->expires_at;

        Carbon::setTestNow(Carbon::createFromTimestamp($expiry)->addDays(AdminRoleInvite::KEPT_EXPIRED_DAYS));
        app(Housekeeping::class)->run();
        $this->assertSame(1, AdminRoleInvite::count());

        Carbon::setTestNow(Carbon::createFromTimestamp($expiry)->addDays(AdminRoleInvite::KEPT_EXPIRED_DAYS)->addSecond());
        app(Housekeeping::class)->run();
        $this->assertSame(0, AdminRoleInvite::count());
    }

    public function test_list_json_holds_the_member_count_the_administrators_and_the_kept_invites(): void
    {
        $admin = Member::factory()->administrator()->create(['email' => 'admin@example.com', 'name' => 'Hanako']);
        $secured = Member::factory()->administrator()->create(['email' => 'secured@example.com', 'name' => 'Jiro']);
        app(EnableTwoFactorAuthentication::class)($secured, force: true);
        DB::table('members')->where('id', $secured->id)->update(['two_factor_confirmed_at' => now()]);
        $secured->passkeys()->create(['name' => 'a', 'credential_id' => 'AbC', 'credential' => []]);
        $secured->passkeys()->create(['name' => 'b', 'credential_id' => 'abc', 'credential' => []]);
        Member::factory()->aiAccount(Member::factory()->create())->create();
        $this->runSeparated('openpne:admin:invite', ['email' => 'live@example.com']);
        AdminRoleInvite::insert([
            ['email' => 'kept@example.com', 'expires_at' => now()->subDays(AdminRoleInvite::KEPT_EXPIRED_DAYS)->getTimestamp(), 'created_at' => now()],
            ['email' => 'gone@example.com', 'expires_at' => now()->subDays(AdminRoleInvite::KEPT_EXPIRED_DAYS)->subSecond()->getTimestamp(), 'created_at' => now()],
        ]);

        [$exit, $stdout, $stderr] = $this->runSeparated('openpne:admin:list', ['--json' => true]);

        $this->assertSame(0, $exit, $stderr);
        $this->assertSame('', $stderr);
        $this->assertSame(1, substr_count($stdout, "\n"));
        $this->assertSame([
            'members' => 4,
            'admins' => [
                ['id' => $admin->id, 'email' => 'admin@example.com', 'name' => 'Hanako', 'two_factor' => false, 'passkeys' => 0, 'granted_at' => '2026-12-01T09:30:00Z'],
                ['id' => $secured->id, 'email' => 'secured@example.com', 'name' => 'Jiro', 'two_factor' => true, 'passkeys' => 2, 'granted_at' => '2026-12-01T09:30:00Z'],
            ],
            'invites' => [
                ['email' => 'kept@example.com', 'expires_at' => '2026-11-24T09:30:00Z', 'expired' => true],
                ['email' => 'live@example.com', 'expires_at' => '2026-12-02T09:30:00Z', 'expired' => false],
            ],
        ], json_decode($stdout, true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_list_json_carries_a_name_past_the_console_formatter(): void
    {
        Member::factory()->administrator()->create(['name' => '<comment>Taro</comment>']);

        [, $stdout] = $this->runSeparated('openpne:admin:list', ['--json' => true]);

        $this->assertSame('<comment>Taro</comment>', json_decode($stdout, true, flags: JSON_THROW_ON_ERROR)['admins'][0]['name']);
    }

    public function test_list_json_counts_an_invite_expired_at_its_expiry_second(): void
    {
        AdminRoleInvite::insert(['email' => 'due@example.com', 'expires_at' => now()->getTimestamp(), 'created_at' => now()]);

        [, $stdout] = $this->runSeparated('openpne:admin:list', ['--json' => true]);

        $this->assertTrue(json_decode($stdout, true, flags: JSON_THROW_ON_ERROR)['invites'][0]['expired']);
    }

    public function test_list_json_of_an_empty_site_is_all_zero(): void
    {
        [$exit, $stdout] = $this->runSeparated('openpne:admin:list', ['--json' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame(['members' => 0, 'admins' => [], 'invites' => []], json_decode($stdout, true, flags: JSON_THROW_ON_ERROR));
    }
}
