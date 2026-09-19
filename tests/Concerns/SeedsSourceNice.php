<?php

namespace Tests\Concerns;

use App\Upgrade\SourceSchema;
use Illuminate\Support\Facades\DB;

/** The OpenPNE 3 opLikePlugin `nice` table from the real DDL, plus a row seeder. */
trait SeedsSourceNice
{
    protected function createSourceNiceTable(): void
    {
        DB::statement('DROP TABLE IF EXISTS `nice`');
        DB::statement(SourceSchema::default()->createStatement('nice', withoutForeignKeys: true));
    }

    /** The same table with the letter column folding case, as a source outside the stock DDL may. */
    protected function createCaseInsensitiveSourceNiceTable(): void
    {
        DB::statement('DROP TABLE IF EXISTS `nice`');
        DB::statement(str_replace('COLLATE utf8mb3_bin', 'COLLATE utf8mb3_general_ci', SourceSchema::default()->createStatement('nice', withoutForeignKeys: true)));
    }

    protected function dropSourceNiceTable(): void
    {
        DB::statement('DROP TABLE IF EXISTS `nice`');
    }

    /** @param  'A'|'D'|'d'|'t'|'e'  $table  the opLikePlugin one-letter target: activity / diary / diary comment / topic comment / event comment */
    protected function seedNice(int $id, int $memberId, string $table, int $foreignId, string $at = '2016-01-02 03:04:05'): void
    {
        DB::table('nice')->insert([
            'id' => $id,
            'member_id' => $memberId,
            'foreign_table' => $table,
            'foreign_id' => $foreignId,
            'foreign_hash' => md5("{$table},{$foreignId}"),
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }
}
