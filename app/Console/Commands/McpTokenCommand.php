<?php

namespace App\Console\Commands;

use App\Features\AiAccount\Actions\IssueMcpToken;
use App\Features\AiAccount\Actions\RevokeMcpTokens;
use App\Features\AiAccount\Exceptions\AiAccountActionException;
use App\Features\AiAccount\Exceptions\AiAccountActionFailure;
use App\Mcp\McpAbilities;
use App\Models\Member;
use App\Support\SecurityLog;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * The operator's way to mint and retire the personal access token an MCP client presents. A token
 * stands in for one member and carries that member's own reach; server access is the trust boundary
 * here, as it is for the other member CLIs. An AI account's owner has a second way in, from their
 * own settings screen (App\Features\AiAccount\AiAccountController) — bounded to the accounts they
 * own, and re-authenticated. Both mint through the same Action, so neither can drift from the
 * other's contract.
 *
 * The member is named by email or by `--id`, exactly one of the two: an AI account has no email
 * address, so the id is the only way to reach one from here.
 */
class McpTokenCommand extends Command
{
    protected $signature = 'openpne:mcp:token
        {email? : The member email address}
        {--id= : The member id, for an account that has no email address}
        {--read-only : Issue a token that may read but not write}
        {--revoke : Delete the member MCP tokens instead of issuing one}';

    protected $description = 'Issue or revoke a member MCP access token';

    public function handle(IssueMcpToken $issue, RevokeMcpTokens $revokeAll): int
    {
        $member = $this->resolveMember();

        if ($member === null) {
            return self::FAILURE;
        }

        return $this->option('revoke') ? $this->revoke($member, $revokeAll) : $this->issue($member, $issue);
    }

    private function issue(Member $member, IssueMcpToken $issue): int
    {
        try {
            $token = $issue($member, (bool) $this->option('read-only'));
        } catch (AiAccountActionException $e) {
            $this->error($this->failureMessage($member, $e->reason));

            return self::FAILURE;
        }

        // Logged and printed only after the Action's transaction committed, so a rolled-back issue
        // is never reported as one.
        SecurityLog::event('token.issued', [
            'member_id' => (int) $member->getKey(),
            'name' => McpAbilities::TOKEN_NAME,
            'abilities' => implode(' ', $token->accessToken->abilities),
            'via' => 'cli',
        ]);

        // The only moment the credential is legible: the row keeps a SHA-256 of it, and it is kept
        // out of every log. A lost token is replaced, never recovered.
        $this->info("Token issued for member [{$this->label($member)}]. Copy it now — it is not shown again.");
        $this->line($token->plainTextToken);

        return self::SUCCESS;
    }

    private function revoke(Member $member, RevokeMcpTokens $revokeAll): int
    {
        $deleted = $revokeAll($member);

        SecurityLog::event('token.revoked', [
            'member_id' => (int) $member->getKey(),
            'name' => McpAbilities::TOKEN_NAME,
            'count' => $deleted,
            'via' => 'cli',
        ]);

        $this->info("Revoked {$deleted} MCP token(s) for member [{$this->label($member)}].");

        return self::SUCCESS;
    }

    /**
     * The member named by the email argument or by `--id`, exactly one of which must be given.
     * Both or neither is a typo worth refusing rather than guessing at.
     */
    private function resolveMember(): ?Member
    {
        $email = trim((string) $this->argument('email'));
        $id = trim((string) $this->option('id'));

        if (($email !== '') === ($id !== '')) {
            $this->error('Name the member either by email argument or by --id, not both and not neither.');

            return null;
        }

        $member = $email !== '' ? $this->byEmail($email) : $this->byId($id);

        if ($member === null) {
            $this->error('Member ['.($email !== '' ? Str::lower($email) : "#{$id}").'] not found.');
        }

        return $member;
    }

    /**
     * Case-insensitive so an upgraded verbatim mixed-case address is still found on a
     * case-sensitive store (IssueRegistrationToken precedent).
     */
    private function byEmail(string $email): ?Member
    {
        return Member::whereRaw('lower(email) = ?', [Str::lower($email)])->first();
    }

    private function byId(string $id): ?Member
    {
        return ctype_digit($id) ? Member::find((int) $id) : null;
    }

    /** An AI account has no address, so its id is what identifies it in these lines. */
    private function label(Member $member): string
    {
        return $member->email ?? '#'.$member->getKey();
    }

    private function failureMessage(Member $member, AiAccountActionFailure $reason): string
    {
        $label = $this->label($member);

        return match ($reason) {
            // A frozen member has had every foothold ended (RejectMemberLogin), and an AI account is
            // a foothold of its owner's; handing either a token would quietly restore that reach.
            AiAccountActionFailure::ActorFrozen => "Member [{$label}] has login rejected, or is owned by a member who has; unfreeze the account first.",
            default => "Member [{$label}] is no longer there.",
        };
    }
}
