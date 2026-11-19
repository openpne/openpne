<?php

namespace App\Upgrade\Runner;

use App\Support\Database\IdentifierCollation;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Steps\AdminUserUpgrade;
use App\Upgrade\Steps\MemberUpgrade;
use App\Upgrade\UpgradeStep;
use Illuminate\Support\Facades\DB;

/**
 * A WARN, not an abort: a login with whitespace at either end matches nothing anyone can type, but it
 * migrates as it is, since the fix is the operator's to choose (docs/internals/text-comparison.md, "Identifiers").
 */
final class LoginWhitespacePreflight
{
    public const W_MEMBER_EMAIL = 'W-MEMBER-EMAIL-WHITESPACE';

    public const W_ADMIN_USERNAME = 'W-ADMIN-USERNAME-WHITESPACE';

    private const SAMPLE = 5;

    /** @param  list<UpgradeStep>  $steps  the run's steps; a login column whose step is not among them is not read */
    public function inspect(array $steps, string $sourcePrefix, ?string $sourceDatabase): PreflightReport
    {
        $warnings = [];

        foreach ($steps as $step) {
            [$column, $code] = match (true) {
                $step instanceof MemberUpgrade => ['email', self::W_MEMBER_EMAIL],
                $step instanceof AdminUserUpgrade => ['username', self::W_ADMIN_USERNAME],
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
            $ids = [];
            foreach (DB::cursor($sql) as $row) {
                if ($row->login !== null && IdentifierCollation::hasEdgeWhitespace((string) $row->login)) {
                    $ids[] = (int) $row->id;
                }
            }

            if ($ids !== []) {
                sort($ids);
                $warnings[] = new Warning($code, self::message($code, count($ids), array_slice($ids, 0, self::SAMPLE)), count($ids));
            }
        }

        return new PreflightReport([], $warnings);
    }

    /** @param  list<int>  $ids */
    public static function message(string $code, int $rows, array $ids): string
    {
        return $code === self::W_MEMBER_EMAIL
            ? "source has {$rows} member(s) whose email has whitespace at either end (e.g. member ids ".implode(', ', $ids)
                .') — OpenPNE 4 trims what is typed, so they cannot sign in or reset their password; trim the address in the source, or afterwards in `members.email`.'
            : "source has {$rows} administrator(s) whose username has whitespace at either end (e.g. admin_user ids ".implode(', ', $ids)
                .") — OpenPNE 4 trims what is typed, so they cannot sign in; trim it in the source, or afterwards on another administrator's edit page.";
    }
}
