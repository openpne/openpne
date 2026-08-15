<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Console\Commands\McpTokenCommand;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\CapturesSecurityLog;
use Tests\TestCase;

class McpTokenCommandTest extends TestCase
{
    use CapturesSecurityLog, RefreshDatabase;

    /** Run the command and return its captured stdout. */
    private function runCommand(array $parameters): array
    {
        $exitCode = Artisan::call('openpne:mcp:token', $parameters);

        return [$exitCode, Artisan::output()];
    }

    /** The plaintext credential the command printed: `{id}|{random}`, alone on its line. */
    private function plainTextTokenIn(string $output): string
    {
        $this->assertSame(1, preg_match('/^\d+\|\S+$/m', $output, $matches), "no token line in output:\n{$output}");

        return $matches[0];
    }

    public function test_issues_a_read_write_token_and_prints_the_working_credential(): void
    {
        $member = Member::factory()->create(['email' => 'pilot@example.com']);

        [$exitCode, $output] = $this->runCommand(['email' => 'pilot@example.com']);

        $this->assertSame(0, $exitCode);
        $token = PersonalAccessToken::sole();
        $this->assertSame(McpTokenCommand::TOKEN_NAME, $token->name);
        $this->assertSame(['mcp:read', 'mcp:write'], $token->abilities);
        $this->assertTrue($token->tokenable->is($member));

        // The printed string must be the credential itself, not a truncated or re-hashed echo of it.
        $this->assertTrue(PersonalAccessToken::findToken($this->plainTextTokenIn($output))?->is($token));
    }

    public function test_read_only_issues_a_token_without_the_write_ability(): void
    {
        Member::factory()->create(['email' => 'pilot@example.com']);

        [$exitCode] = $this->runCommand(['email' => 'pilot@example.com', '--read-only' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(['mcp:read'], PersonalAccessToken::sole()->abilities);
    }

    public function test_the_email_lookup_ignores_case_and_surrounding_space(): void
    {
        // Mixed-case on the STORED side: the app's own creation paths lowercase, but an upgraded
        // row is verbatim, and SQLite compares `=` case-sensitively — the lookup must lower both.
        $member = Member::factory()->create(['email' => 'Pilot@Example.com']);

        [$exitCode] = $this->runCommand(['email' => '  pilot@EXAMPLE.com ']);

        $this->assertSame(0, $exitCode);
        $this->assertTrue(PersonalAccessToken::sole()->tokenable->is($member));
    }

    public function test_revoke_deletes_only_the_tokens_this_command_issued(): void
    {
        $member = Member::factory()->create(['email' => 'pilot@example.com']);
        // Explicit narrow ability: omitting it would mint Sanctum's wildcard ['*'] token, which
        // could pass ability gates and so wouldn't stand for "a PAT of some other purpose".
        $other = $member->createToken('other', ['reporting'])->accessToken;
        $this->runCommand(['email' => 'pilot@example.com']);

        [$exitCode, $output] = $this->runCommand(['email' => 'pilot@example.com', '--revoke' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Revoked 1', $output);
        // A PAT minted for some other purpose is not collateral damage of an MCP revocation.
        $this->assertSame([$other->getKey()], PersonalAccessToken::pluck('id')->all());
    }

    public function test_revoke_reports_zero_when_the_member_holds_no_mcp_token(): void
    {
        Member::factory()->create(['email' => 'pilot@example.com']);

        [$exitCode, $output] = $this->runCommand(['email' => 'pilot@example.com', '--revoke' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Revoked 0', $output);
    }

    public function test_revoke_leaves_another_members_mcp_token_alone(): void
    {
        $member = Member::factory()->create(['email' => 'pilot@example.com']);
        $bystander = Member::factory()->create(['email' => 'other@example.com']);
        $this->runCommand(['email' => 'pilot@example.com']);
        $this->runCommand(['email' => 'other@example.com']);

        $this->runCommand(['email' => 'pilot@example.com', '--revoke' => true]);

        $survivor = PersonalAccessToken::sole();
        $this->assertTrue($survivor->tokenable->is($bystander));
        $this->assertSame(0, $member->tokens()->count());
    }

    public function test_refuses_to_issue_to_a_frozen_member(): void
    {
        Member::factory()->create(['email' => 'banned@example.com', 'is_login_rejected' => true]);

        [$exitCode, $output] = $this->runCommand(['email' => 'banned@example.com']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('login rejected', $output);
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_refuses_an_unknown_email(): void
    {
        [$exitCode, $output] = $this->runCommand(['email' => 'nobody@example.com']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('not found', $output);
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_logs_the_issue_and_revoke_events_without_the_credential(): void
    {
        $member = Member::factory()->create(['email' => 'pilot@example.com']);
        $this->captureSecurityLog();

        [, $output] = $this->runCommand(['email' => 'pilot@example.com']);
        $plainTextToken = $this->plainTextTokenIn($output);
        $this->runCommand(['email' => 'pilot@example.com', '--revoke' => true]);

        $issued = $this->assertOneSecurityEvent('token.issued');
        $this->assertSame((string) $member->getKey(), $issued['member_id']);
        $this->assertSame('mcp:read mcp:write', $issued['abilities']);
        $this->assertSame('1', $this->assertOneSecurityEvent('token.revoked')['count']);

        // The audit trail records that a token exists, never what it is.
        $this->assertStringNotContainsString(
            $plainTextToken,
            json_encode($this->securityRecords(), JSON_THROW_ON_ERROR),
        );
    }
}
