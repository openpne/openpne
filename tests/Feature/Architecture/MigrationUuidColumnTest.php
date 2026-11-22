<?php

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * The schema builder's uuid columns are a native `uuid` type on MariaDB 10.7 and later, which stores
 * and orders differently from MySQL's `char(36)`, so a migration names `char(36)`.
 */
class MigrationUuidColumnTest extends TestCase
{
    public function test_no_migration_uses_a_uuid_column_type(): void
    {
        $offending = [];
        foreach (glob(database_path('migrations/*.php')) ?: [] as $path) {
            foreach (file($path) ?: [] as $number => $line) {
                if (preg_match("/->(?:uuid|foreignUuid|uuidMorphs|nullableUuidMorphs)\\s*\\(|addColumn\\(\\s*['\"]uuid['\"]|morphUsingUuids|defaultMorphKeyType\\(\\s*['\"]uuid['\"]/", $line) === 1) {
                    $offending[] = basename($path).':'.($number + 1);
                }
            }
        }

        $this->assertSame([], $offending, 'a uuid column type: use char(36)');
    }
}
