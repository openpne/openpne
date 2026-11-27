<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\FindsMemberArgument;
use App\Features\Member\Actions\AdministratorRoleResult;
use App\Features\Member\Actions\RevokeAdministrator;
use Illuminate\Console\Command;

/** See docs/internals/administrators.md, "The last administrator". */
class RevokeAdministratorCommand extends Command
{
    use FindsMemberArgument;

    protected $signature = 'openpne:admin:revoke {member : The member\'s email address or id}';

    protected $description = 'Take the administrator role from a member';

    public function handle(RevokeAdministrator $revoke): int
    {
        $member = $this->memberArgument();
        $result = $member === null ? AdministratorRoleResult::MemberGone : $revoke($member);
        $name = $this->shownMemberArgument();

        $message = match ($result) {
            AdministratorRoleResult::Revoked => "Member [{$name}] is no longer an administrator.",
            AdministratorRoleResult::NotAdministrator => "Member [{$name}] is not an administrator; nothing changed.",
            AdministratorRoleResult::MemberGone => "Member [{$name}] not found.",
            AdministratorRoleResult::LastAdministrator => "Member [{$name}] is the last administrator: make another member one first (openpne:admin:grant).",
            default => "Member [{$name}] kept the administrator role ({$result->name}).",
        };

        return $this->answer($result, $message);
    }
}
