<?php

namespace App\Console\Commands;

use App\Features\Auth\Actions\IssueRegistrationToken;
use App\Features\Auth\Actions\IssueResult;
use App\Features\Auth\RegistrationTokenSource;
use App\Models\AdminRoleInvite;
use App\Rules\NewEmailAddress;
use App\Support\SecurityLog;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Stdout carries only the link (or its JSON), written raw; every failure, and the note unless --json,
 * goes to stderr. Issued in every registration mode, closed included, since it is the way back in when
 * no one can sign in to the admin panel (docs/internals/administrators.md, "Inviting an administrator").
 */
class InviteAdministratorCommand extends Command
{
    protected $signature = 'openpne:admin:invite
        {email : The address of the member to be}
        {--json : Print email, url and expires_at as one line of JSON instead of the bare URL}';

    protected $description = 'Print a registration link whose registration makes the new member an administrator';

    public function handle(IssueRegistrationToken $issue, ExceptionHandler $exceptions): int
    {
        try {
            return $this->invite($issue);
        } catch (Throwable $e) {
            // The console kernel would render it to stdout, also when report() throws because no log is writable.
            $exceptions->renderForConsole($this->getOutput()->getErrorStyle(), $e);
            try {
                $exceptions->report($e);
            } catch (Throwable) {
            }

            return self::FAILURE;
        }
    }

    private function invite(IssueRegistrationToken $issue): int
    {
        $stderr = $this->getOutput()->getErrorStyle();
        $email = Str::lower(Str::trim((string) $this->argument('email')));
        $shown = OutputFormatter::escape($email);

        if (Validator::make(['email' => $email], ['email' => NewEmailAddress::rules()])->fails()) {
            $stderr->error("[{$shown}] is not an email address.");

            return self::FAILURE;
        }

        $expiresAt = null;
        $link = $issue->unmailedLink($email, RegistrationTokenSource::AdminRoleInvite, function (CarbonInterface $createdAt) use ($email, &$expiresAt): void {
            $expiresAt = $createdAt->copy()->addMinutes((int) config('openpne.registration.token_ttl_minutes'));
            AdminRoleInvite::upsert(
                [['email' => $email, 'expires_at' => $expiresAt->getTimestamp(), 'created_at' => $createdAt]],
                ['email'],
                ['expires_at', 'created_at'],
            );
        });

        if ($link instanceof IssueResult) {
            $stderr->error(match ($link) {
                IssueResult::AlreadyMember => "A member already uses [{$shown}]: make them an administrator with openpne:admin:grant.",
                IssueResult::CapacityFull => 'The site is at its member capacity (php artisan openpne:capacity).',
                IssueResult::Issued => 'No link was issued.',
            });

            return self::FAILURE;
        }

        $expires = $expiresAt->toIso8601ZuluString();
        SecurityLog::event('registration.link_printed', ['email' => $email, 'via' => 'cli', 'role' => 'admin', 'expires_at' => $expires]);

        $this->output->writeln($this->option('json')
            ? json_encode(['email' => $email, 'url' => $link, 'expires_at' => $expires], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            : $link, OutputInterface::OUTPUT_RAW);

        if (! $this->option('json')) {
            $stderr->writeln("Whoever registers as [{$shown}] until {$expires} becomes an administrator, through this link or a newer one for the address. The link works once and is built from APP_URL.");
        }

        return self::SUCCESS;
    }
}
