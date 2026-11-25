<?php

namespace App\Console\Commands;

use App\Models\AdminPasswordSetupToken;
use App\Models\AdminUser;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

class ListAdminUsersCommand extends Command
{
    protected $signature = 'openpne:admin:list {--json : Print one line of JSON: a list of username, password_set, two_factor, link_expires_at and created_at}';

    protected $description = 'List the administrators, whether each has a password, and any unused password link';

    public function handle(): int
    {
        $links = AdminPasswordSetupToken::where('expires_at', '>', now()->getTimestamp())->pluck('expires_at', 'admin_user_id');

        $admins = AdminUser::orderBy('id')->get()->map(function (AdminUser $admin) use ($links): array {
            $password = $admin->getAuthPassword();

            return [
                'username' => $admin->username,
                // An empty string counts as unset: no sign-in can match it.
                'password_set' => is_string($password) && $password !== '',
                'two_factor' => filled($admin->getRawOriginal('app_authentication_secret')),
                'link_expires_at' => $links->has($admin->getKey()) ? Carbon::createFromTimestampUTC($links->get($admin->getKey()))->toIso8601ZuluString() : null,
                'created_at' => $admin->created_at?->toIso8601ZuluString(),
            ];
        });

        if ($this->option('json')) {
            // Raw, so the console formatter cannot alter a username.
            $this->output->writeln(json_encode($admins->all(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

        if ($admins->isEmpty()) {
            $this->line('No administrators.');

            return self::SUCCESS;
        }

        $this->table(
            ['Username', 'Password', 'Two-factor', 'Link expires', 'Created'],
            $admins->map(fn (array $row): array => [
                OutputFormatter::escape($row['username']),
                $row['password_set'] ? 'set' : 'not set',
                $row['two_factor'] ? 'on' : 'off',
                $row['link_expires_at'] ?? '-',
                $row['created_at'] ?? '-',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
