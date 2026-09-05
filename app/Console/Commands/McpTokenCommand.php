<?php

namespace App\Console\Commands;

use App\Features\AiAccount\Actions\IssueMcpToken;
use App\Features\AiAccount\Actions\RevokeMcpTokens;
use App\Features\AiAccount\Exceptions\AiAccountActionException;
use App\Features\AiAccount\Exceptions\AiAccountActionFailure;
use App\Features\AiAccount\MemberSelector;
use App\Mcp\McpAbilities;
use App\Models\Member;
use App\Support\SecurityLog;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * See docs/internals/mcp.md "Tokens and abilities".
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
        $selector = $this->resolveMember();

        if ($selector === null) {
            return self::FAILURE;
        }

        return $this->option('revoke') ? $this->revoke($selector, $revokeAll) : $this->issue($selector, $issue);
    }

    private function issue(MemberSelector $selector, IssueMcpToken $issue): int
    {
        $member = $selector->member();

        try {
            $token = $issue($selector, (bool) $this->option('read-only'));
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

        // The only moment the credential is legible: the row keeps a SHA-256 of it and no log carries it.
        $this->info("Token issued for member [{$this->label($member)}]. Copy it now — it is not shown again.");
        $this->line($token->plainTextToken);

        return self::SUCCESS;
    }

    private function revoke(MemberSelector $selector, RevokeMcpTokens $revokeAll): int
    {
        $member = $selector->member();
        $deleted = $revokeAll($selector);

        if ($deleted === null) {
            $this->error($this->failureMessage($member, AiAccountActionFailure::MemberGone));

            return self::FAILURE;
        }

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
     * This lookup only decides what to say about an id that names nobody: what is acted on is decided
     * by the act, which locks the row and confirms the address there ({@see MemberSelector}).
     */
    private function resolveMember(): ?MemberSelector
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

            return null;
        }

        return $email !== '' ? MemberSelector::foundByEmail($member, $email) : MemberSelector::of($member);
    }

    /** Case-insensitive so an upgraded verbatim mixed-case address is still found on a case-sensitive store. */
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
