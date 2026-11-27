<?php

namespace App\Console\Commands\Concerns;

use App\Features\Member\Actions\AdministratorRoleResult;
use App\Models\Member;
use Illuminate\Support\Str;
use Symfony\Component\Console\Formatter\OutputFormatter;

/** For a command whose `member` argument is a member's id or email address. */
trait FindsMemberArgument
{
    private function memberArgument(): ?Member
    {
        $given = Str::trim((string) $this->argument('member'));

        return ctype_digit($given)
            ? Member::find((int) $given)
            : Member::where('email', Str::lower($given))->first();
    }

    private function shownMemberArgument(): string
    {
        return OutputFormatter::escape(Str::trim((string) $this->argument('member')));
    }

    /** Exit 0 only when the role is now as asked; a refusal goes to stderr. */
    private function answer(AdministratorRoleResult $result, string $message): int
    {
        if ($result->succeeded()) {
            $this->info($message);

            return self::SUCCESS;
        }

        $this->getOutput()->getErrorStyle()->error($message);

        return self::FAILURE;
    }
}
