<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Support\SecurityLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;

/**
 * Mint and retire the personal access token an MCP client presents. A token stands in for one
 * member and carries that member's own reach, so issuing one is an act of server access (the
 * operator trust boundary), not something a member can do for themselves from a screen.
 */
class McpTokenCommand extends Command
{
    /** Stamped on every token this command issues, so --revoke can drop these and leave other tokens alone. */
    public const TOKEN_NAME = 'mcp';

    public const ABILITY_READ = 'mcp:read';

    public const ABILITY_WRITE = 'mcp:write';

    protected $signature = 'openpne:mcp:token
        {email : The member email address}
        {--read-only : Issue a token that may read but not write}
        {--revoke : Delete the member MCP tokens instead of issuing one}';

    protected $description = 'Issue or revoke a member MCP access token';

    public function handle(): int
    {
        return $this->option('revoke') ? $this->revoke() : $this->issue();
    }

    private function issue(): int
    {
        $abilities = $this->option('read-only')
            ? [self::ABILITY_READ]
            : [self::ABILITY_READ, self::ABILITY_WRITE];

        /** @var array{token: NewAccessToken, member_id: int, email: string}|null $issued */
        $issued = null;

        // Decide and mint on the row lock: the ban and withdrawal sweeps serialize on the same
        // member row (RejectMemberLogin's UPDATE, WithdrawMember's locked DELETE), so a token can
        // never be created from a stale read after one of them has swept — which would quietly
        // re-open the very hole those sweeps close.
        $status = DB::transaction(function () use ($abilities, &$issued): int {
            $member = $this->lockMember();
            if ($member === null) {
                return self::FAILURE;
            }

            // A frozen member has had every foothold ended (RejectMemberLogin); handing them a
            // token would quietly restore the access the freeze took away.
            if ($member->is_login_rejected) {
                $this->error("Member [{$member->email}] has login rejected; unfreeze the account first.");

                return self::FAILURE;
            }

            // The scalars are read here, under the lock: resolving the token's tokenable after
            // commit would be a fresh query that a withdrawal committing in between nulls out.
            $issued = [
                'token' => $member->createToken(self::TOKEN_NAME, $abilities),
                'member_id' => (int) $member->getKey(),
                'email' => $member->email,
            ];

            return self::SUCCESS;
        });

        if ($status !== self::SUCCESS || $issued === null) {
            return $status;
        }

        // Logged and printed only after the commit, so a rolled-back issue is never reported as one.
        SecurityLog::event('token.issued', [
            'member_id' => $issued['member_id'],
            'name' => self::TOKEN_NAME,
            'abilities' => implode(' ', $abilities),
            'via' => 'cli',
        ]);

        // The only moment the credential is legible: the row keeps a SHA-256 of it, and it is kept
        // out of every log. A lost token is replaced, never recovered.
        $this->info("Token issued for member [{$issued['email']}]. Copy it now — it is not shown again.");
        $this->line($issued['token']->plainTextToken);

        return self::SUCCESS;
    }

    private function revoke(): int
    {
        /** @var array{member: Member, deleted: int}|null $result */
        $result = null;

        // Same row lock as issue(): a revoke that raced a concurrent issue could otherwise report
        // "Revoked 0" while a fresh token lands right behind it.
        $status = DB::transaction(function () use (&$result): int {
            $member = $this->lockMember();
            if ($member === null) {
                return self::FAILURE;
            }

            $result = [
                'member' => $member,
                'deleted' => $member->tokens()->where('name', self::TOKEN_NAME)->delete(),
            ];

            return self::SUCCESS;
        });

        if ($status !== self::SUCCESS || $result === null) {
            return $status;
        }

        SecurityLog::event('token.revoked', [
            'member_id' => $result['member']->getKey(),
            'name' => self::TOKEN_NAME,
            'count' => $result['deleted'],
            'via' => 'cli',
        ]);

        $this->info("Revoked {$result['deleted']} MCP token(s) for member [{$result['member']->email}].");

        return self::SUCCESS;
    }

    /**
     * Case-insensitive so an upgraded verbatim mixed-case address is still found on a
     * case-sensitive store (IssueRegistrationToken precedent); locked so every decision that
     * follows reads the row as it is now, not as it was when the command started.
     */
    private function lockMember(): ?Member
    {
        $email = Str::lower(trim((string) $this->argument('email')));

        $member = Member::whereRaw('lower(email) = ?', [$email])->lockForUpdate()->first();
        if ($member === null) {
            $this->error("Member [{$email}] not found.");
        }

        return $member;
    }
}
