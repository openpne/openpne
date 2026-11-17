<?php

namespace App\Console\Commands;

use App\Features\Auth\Actions\IssueRegistrationToken;
use App\Features\Auth\Actions\IssueResult;
use App\Features\Auth\RegistrationMode;
use App\Features\Auth\RegistrationTokenSource;
use App\Support\SecurityLog;
use Carbon\CarbonInterval;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * See docs/internals/security.md, "Registration links".
 */
class InviteMemberCommand extends Command
{
    protected $signature = 'openpne:member:invite {email : The address the registration link is for}';

    protected $description = 'Print a registration link for an address instead of mailing it';

    public function handle(IssueRegistrationToken $issue): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));

        if (Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']])->fails()) {
            $this->error("[{$email}] is not an email address.");

            return self::FAILURE;
        }

        if (! RegistrationMode::current()->allowsAdminInvite()) {
            $this->error('Registration is closed on this site, so a link would not complete.');

            return self::FAILURE;
        }

        $link = $issue->link($email, RegistrationTokenSource::AdminInvite);

        if ($link instanceof IssueResult) {
            $this->error(match ($link) {
                IssueResult::AlreadyMember => "A member already uses [{$email}].",
                IssueResult::CapacityFull => 'The site is at its member capacity (php artisan openpne:capacity).',
                IssueResult::Issued => 'No link was issued.',
            });

            return self::FAILURE;
        }

        SecurityLog::event('registration.link_printed', ['email' => $email, 'via' => 'cli']);

        $lifetime = CarbonInterval::minutes((int) config('openpne.registration.token_ttl_minutes'))->cascade()->locale('en')->forHumans();
        $this->line($link);
        $this->newLine();
        $this->info("Whoever completes the form behind this link registers as [{$email}]. It works once, for {$lifetime}, and a newer link for the address replaces it.");
        $this->comment('The link starts with '.url('/').', which comes from APP_URL: if the site answers somewhere else, fix APP_URL and issue it again.');

        return self::SUCCESS;
    }
}
