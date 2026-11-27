<?php

namespace App\Upgrade\Runner;

use App\Support\Database\IdentifierCollation;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Steps\MemberUpgrade;
use App\Upgrade\UpgradeStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A WARN, not an abort: a login with whitespace at either end, or a member email the identifier collation
 * may fold into another address, migrates as it is, since the fix is the operator's to choose (docs/internals/text-comparison.md, "Identifiers").
 * The uncopied `admin_user` rows are a WARN too, and an absent table none (docs/internals/upgrade.md, "Source preflight").
 */
final class LoginPreflight
{
    public const W_MEMBER_EMAIL = 'W-MEMBER-EMAIL-WHITESPACE';

    public const W_MEMBER_EMAIL_NON_ASCII = 'W-MEMBER-EMAIL-NON-ASCII';

    public const W_ADMIN_USER_NOT_COPIED = 'W-ADMIN-USER-NOT-COPIED';

    private const SAMPLE = 5;

    /** @param  list<UpgradeStep>  $steps  the run's steps; a login column whose step is not among them is not read */
    public function inspect(array $steps, string $sourcePrefix, ?string $sourceDatabase): PreflightReport
    {
        $warnings = [];

        foreach ($steps as $step) {
            [$column, $code] = match (true) {
                $step instanceof MemberUpgrade => ['email', self::W_MEMBER_EMAIL],
                default => [null, null],
            };
            if ($column === null) {
                continue;
            }

            $sql = (new InsertSelectCompiler)->compileQuery(
                $step,
                "`{$step->sourceTable()}`.`id` AS `id`, {$step->columns()[$column]->selectSql()} AS `login`",
                '',
                $sourcePrefix,
                $sourceDatabase,
            );
            $found = [$code => []];
            foreach (DB::cursor($sql) as $row) {
                if ($row->login === null) {
                    continue;
                }
                if (IdentifierCollation::hasEdgeWhitespace((string) $row->login)) {
                    $found[$code][] = (int) $row->id;
                }
                // On the trimmed value, so padding alone is reported only as whitespace.
                if ($code === self::W_MEMBER_EMAIL && preg_match('/[^\x21-\x7E]/', Str::trim((string) $row->login)) === 1) {
                    $found[self::W_MEMBER_EMAIL_NON_ASCII][] = (int) $row->id;
                }
            }

            foreach ($found as $foundCode => $ids) {
                if ($ids !== []) {
                    sort($ids);
                    $warnings[] = new Warning($foundCode, self::message($foundCode, count($ids), array_slice($ids, 0, self::SAMPLE)), count($ids));
                }
            }
        }

        $admins = $this->adminUserRows($sourcePrefix, $sourceDatabase);
        if ($admins > 0) {
            $warnings[] = new Warning(self::W_ADMIN_USER_NOT_COPIED, self::message(self::W_ADMIN_USER_NOT_COPIED, $admins, []), $admins);
        }

        return new PreflightReport([], $warnings);
    }

    private function adminUserRows(string $sourcePrefix, ?string $sourceDatabase): int
    {
        $exists = DB::selectOne(
            'select 1 from information_schema.tables where table_schema = ? and table_name = ? limit 1',
            [$sourceDatabase ?? DB::connection()->getDatabaseName(), $sourcePrefix.'admin_user'],
        ) !== null;

        return $exists
            ? (int) DB::selectOne('select count(*) as n from '.InsertSelectCompiler::qualify($sourceDatabase, $sourcePrefix, 'admin_user'))->n
            : 0;
    }

    /** @param  list<int>  $ids */
    public static function message(string $code, int $rows, array $ids): string
    {
        return match ($code) {
            self::W_MEMBER_EMAIL => "source has {$rows} member(s) whose email has whitespace at either end (e.g. member ids ".implode(', ', $ids)
                .') — OpenPNE 4 trims what is typed, so they cannot sign in or reset their password; trim the address in the source, or afterwards in `members.email`.',
            self::W_MEMBER_EMAIL_NON_ASCII => "source has {$rows} member(s) whose email has a character outside printable ASCII (e.g. member ids ".implode(', ', $ids)
                .') — they migrate as they are, but a browser\'s email field refuses a non-ASCII local part and may send a non-ASCII domain as punycode, so they may not sign in or reset their password from a browser, and on MySQL one may hold an ASCII address it compares equal to (`ｕｓｅｒ@` is `user@`); replace it in `members.email` with an ASCII address the member reads.',
            default => "source has {$rows} administrator account(s) in `admin_user`, which are not copied: an OpenPNE 4 administrator is a member holding the administrator role. After the upgrade, give the role with `php artisan openpne:admin:grant <email>`, or print a registration link with `openpne:admin:invite <email>` for someone who is not a member.",
        };
    }
}
