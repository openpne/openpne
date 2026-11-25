<?php

namespace App\Console\Commands;

use App\Auth\AdminPasswords;
use App\Models\AdminUser;
use App\Support\SecurityLog;
use Illuminate\Console\Command;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Str;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Stdout carries only the link (or its JSON), written raw so the console formatter cannot alter a username.
 * Every failure, and the note unless --json, goes to stderr; one after the issue may already have replaced
 * the earlier link (docs/internals/security.md, "Administrator password links").
 */
class AdminPasswordLinkCommand extends Command
{
    public const MAX_MINUTES = 1440;

    protected $signature = 'openpne:admin:password-link
        {username : The administrator username}
        {--minutes=60 : Minutes until the link expires, 1 to 1440}
        {--json : Print username, url and expires_at as one line of JSON instead of the bare URL}';

    protected $description = 'Print a one-time link through which an administrator sets their password';

    public function handle(AdminPasswords $passwords, ExceptionHandler $exceptions): int
    {
        try {
            return $this->issue($passwords);
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

    private function issue(AdminPasswords $passwords): int
    {
        $stderr = $this->getOutput()->getErrorStyle();

        $minutes = (string) $this->option('minutes');
        if (! ctype_digit($minutes) || (int) $minutes < 1 || (int) $minutes > self::MAX_MINUTES) {
            $stderr->error('--minutes must be a whole number from 1 to '.self::MAX_MINUTES.'.');

            return self::FAILURE;
        }

        $username = Str::trim((string) $this->argument('username'));
        $admin = AdminUser::where('username', $username)->first();
        if ($admin === null) {
            $stderr->error("Administrator [{$username}] not found.");

            return self::FAILURE;
        }

        [$token, $expiresAt] = $passwords->issueSetupLink($admin, (int) $minutes);
        $url = route('admin.password_setup', ['token' => $token]);
        $expires = $expiresAt->toIso8601ZuluString();

        SecurityLog::event('password.link_printed', ['guard' => 'admin', 'username' => $admin->username, 'via' => 'cli', 'expires_at' => $expires]);

        $this->output->writeln($this->option('json')
            ? json_encode(['username' => $admin->username, 'url' => $url, 'expires_at' => $expires], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            : $url, OutputInterface::OUTPUT_RAW);

        if (! $this->option('json')) {
            $name = OutputFormatter::escape($admin->username);
            $stderr->writeln("Link for administrator [{$name}], valid until {$expires} and for one use, built from APP_URL. It replaces any earlier link; the current password and sessions stay until it is used.");
        }

        return self::SUCCESS;
    }
}
