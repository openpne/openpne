<?php

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * A migration's MySQL branch runs on MariaDB too: a driver name compared with `mysql` names `mariadb`
 * on the same line, and a file's per-driver arms and keys for `mysql` are matched by as many for `mariadb`.
 */
class MigrationDriverFamilyTest extends TestCase
{
    public function test_no_migration_branches_on_mysql_alone(): void
    {
        $offending = [];
        foreach (glob(database_path('migrations/*.php')) ?: [] as $path) {
            $lines = file($path) ?: [];
            $arms = [];
            $mariaDbArms = 0;
            foreach ($lines as $number => $line) {
                $where = basename($path).':'.($number + 1);
                $mariaDbArms += preg_match_all("/['\"]mariadb['\"]\\s*=>/", preg_replace("/['\"]mysql['\"]\\s*,\\s*['\"]mariadb['\"]/", '', $line));
                if (preg_match("/['\"]mysql['\"]\\s*=>/", $line) === 1) {
                    $arms[] = $where;
                }
                if (preg_match("/['\"]mysql['\"](?!\\s*=>)/", $line) === 1 && preg_match("/['\"]mariadb['\"]/", $line) !== 1) {
                    $offending[] = $where;
                }
            }
            if (count($arms) > $mariaDbArms) {
                array_push($offending, ...$arms);
            }
        }

        $this->assertSame([], $offending, "a MySQL-only branch: name 'mariadb' beside 'mysql'");
    }
}
