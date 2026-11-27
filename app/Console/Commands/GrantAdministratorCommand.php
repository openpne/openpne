<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\FindsMemberArgument;
use App\Features\Member\Actions\AdministratorRoleResult;
use App\Features\Member\Actions\GrantAdministrator;
use Illuminate\Console\Command;

/** See docs/internals/administrators.md, "Who can hold the role". */
class GrantAdministratorCommand extends Command
{
    use FindsMemberArgument;

    protected $signature = 'openpne:admin:grant {member : The member\'s email address or id}';

    protected $description = 'Make an existing member an administrator';

    public function handle(GrantAdministrator $grant): int
    {
        $member = $this->memberArgument();
        $result = $member === null ? AdministratorRoleResult::MemberGone : $grant($member);
        $name = $this->shownMemberArgument();

        $message = match ($result) {
            AdministratorRoleResult::Granted => "Member [{$name}] is now an administrator.",
            AdministratorRoleResult::AlreadyAdministrator => "Member [{$name}] is already an administrator; nothing changed.",
            AdministratorRoleResult::MemberGone => "Member [{$name}] not found.",
            AdministratorRoleResult::AiAccount => "Member [{$name}] is an AI account, which cannot be an administrator.",
            AdministratorRoleResult::Withdrawing => "Member [{$name}] is being withdrawn.",
            AdministratorRoleResult::LoginRejected => "Member [{$name}] has login rejected; allow their login first.",
            default => "Member [{$name}] was not made an administrator ({$result->name}).",
        };

        return $this->answer($result, $message);
    }
}
