<?php

namespace App\Console\Commands;

use App\Models\AdminRoleInvite;
use App\Models\Member;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

/** The JSON shape is a contract that hosting automation reads (docs/internals/administrators.md, "Command line"). */
class ListAdminUsersCommand extends Command
{
    protected $signature = 'openpne:admin:list {--json : Print one line of JSON: the member count, the administrators and the administrator invites}';

    protected $description = 'List the administrators and the pending administrator invites';

    public function handle(): int
    {
        $admins = Member::query()
            ->whereNotNull('admin_granted_at')
            ->withCount('passkeys')
            ->orderBy('id')
            ->get()
            ->map(fn (Member $admin): array => [
                'id' => (int) $admin->getKey(),
                'email' => $admin->email,
                'name' => (string) $admin->name,
                'two_factor' => $admin->hasEnabledTwoFactorAuthentication(),
                'passkeys' => (int) $admin->passkeys_count,
                'granted_at' => $admin->admin_granted_at?->toIso8601ZuluString(),
            ]);

        $now = now()->getTimestamp();
        // Filtered here as well as pruned, so the listing holds to the kept window whenever housekeeping last ran.
        $invites = AdminRoleInvite::query()
            ->where('expires_at', '>=', now()->subDays(AdminRoleInvite::KEPT_EXPIRED_DAYS)->getTimestamp())
            ->orderBy('expires_at')
            ->get()
            ->map(fn (AdminRoleInvite $invite): array => [
                'email' => $invite->email,
                'expires_at' => Carbon::createFromTimestampUTC($invite->expires_at)->toIso8601ZuluString(),
                'expired' => $invite->expires_at <= $now,
            ]);

        $members = Member::count();

        if ($this->option('json')) {
            // Raw, so the console formatter cannot alter a name or an address.
            $this->output->writeln(json_encode([
                'members' => $members,
                'admins' => $admins->all(),
                'invites' => $invites->all(),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

        $this->line("Members: {$members}");

        if ($admins->isEmpty()) {
            $this->line('No administrators.');
        } else {
            $this->table(
                ['ID', 'Email', 'Name', 'Two-factor', 'Passkeys', 'Granted'],
                $admins->map(fn (array $row): array => [
                    $row['id'],
                    OutputFormatter::escape((string) $row['email']),
                    OutputFormatter::escape($row['name']),
                    $row['two_factor'] ? 'on' : 'off',
                    $row['passkeys'],
                    $row['granted_at'] ?? '-',
                ])->all(),
            );
        }

        if ($invites->isNotEmpty()) {
            $this->table(
                ['Invited email', 'Expires', 'Expired'],
                $invites->map(fn (array $row): array => [
                    OutputFormatter::escape($row['email']),
                    $row['expires_at'],
                    $row['expired'] ? 'yes' : 'no',
                ])->all(),
            );
        }

        return self::SUCCESS;
    }
}
