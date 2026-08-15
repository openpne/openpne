<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Support\SecurityLog;
use Illuminate\Console\Command;

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
        $email = mb_strtolower(trim((string) $this->argument('email')));

        $member = Member::where('email', $email)->first();
        if ($member === null) {
            $this->error("Member [{$email}] not found.");

            return self::FAILURE;
        }

        return $this->option('revoke') ? $this->revoke($member) : $this->issue($member);
    }

    private function issue(Member $member): int
    {
        // A frozen member has had every foothold ended (RejectMemberLogin); handing them a token
        // would quietly restore the access the freeze took away.
        if ($member->is_login_rejected) {
            $this->error("Member [{$member->email}] has login rejected; unfreeze the account first.");

            return self::FAILURE;
        }

        $abilities = $this->option('read-only')
            ? [self::ABILITY_READ]
            : [self::ABILITY_READ, self::ABILITY_WRITE];

        $token = $member->createToken(self::TOKEN_NAME, $abilities);

        SecurityLog::event('token.issued', [
            'member_id' => $member->getKey(),
            'name' => self::TOKEN_NAME,
            'abilities' => implode(' ', $abilities),
            'via' => 'cli',
        ]);

        // The only moment the credential is legible: the row keeps a SHA-256 of it, and it is kept
        // out of every log. A lost token is replaced, never recovered.
        $this->info("Token issued for member [{$member->email}]. Copy it now — it is not shown again.");
        $this->line($token->plainTextToken);

        return self::SUCCESS;
    }

    private function revoke(Member $member): int
    {
        $deleted = $member->tokens()->where('name', self::TOKEN_NAME)->delete();

        SecurityLog::event('token.revoked', [
            'member_id' => $member->getKey(),
            'name' => self::TOKEN_NAME,
            'count' => $deleted,
            'via' => 'cli',
        ]);

        $this->info("Revoked {$deleted} MCP token(s) for member [{$member->email}].");

        return self::SUCCESS;
    }
}
