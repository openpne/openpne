<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Runs with no old code writing: the old comment path bumps updated_at only, and the new one needs
 * the column (docs/internals/group-boards.md, "The board key is bumped_at").
 */
return new class extends Migration
{
    /** @var array<string, array{string, string, string}> table => [comments table, comment FK, dead column] */
    private const BOARDS = [
        'group_topics' => ['group_topic_comments', 'group_topic_id', 'topic_updated_at'],
        'group_events' => ['group_event_comments', 'group_event_id', 'event_updated_at'],
    ];

    public function up(): void
    {
        // Checked before any DDL, so a refusal leaves the schema untouched.
        foreach (array_keys(self::BOARDS) as $table) {
            $untimed = DB::table($table)->whereNull('created_at')->count();
            if ($untimed > 0) {
                throw new RuntimeException("{$table}: {$untimed} row(s) have no created_at; bumped_at needs a time to fall back to.");
            }
        }

        foreach (self::BOARDS as $table => [$comments, $fk, $dead]) {
            // Every phase is guarded, so a run that failed on the second table resumes on the first.
            if (! Schema::hasColumn($table, 'bumped_at')) {
                // timestamp, like created_at from timestamps(): the two are compared and assigned in SQL.
                Schema::table($table, function (Blueprint $t) {
                    $t->timestamp('bumped_at')->nullable();
                    $t->timestamp('edited_at')->nullable();
                });
            }

            DB::update("UPDATE {$table} SET bumped_at = COALESCE((SELECT MAX(c.created_at) FROM {$comments} AS c WHERE c.{$fk} = {$table}.id), created_at)");

            // change() rebuilds the table on SQLite, safe only because neither table has a composite primary key or a unique index.
            $sequence = $this->sqliteSequence($table);
            // No DEFAULT: a write path that forgets bumped_at fails instead of storing the engine's clock.
            Schema::table($table, function (Blueprint $t) {
                $t->timestamp('bumped_at')->nullable(false)->change();
            });
            $this->restoreSqliteSequence($table, $sequence);

            // Add before drop: InnoDB backs the group_id foreign key with whichever of these indexes exists (errno 1553).
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasIndex($table, ['group_id', 'bumped_at'])) {
                    $t->index(['group_id', 'bumped_at']);
                }
                if (! Schema::hasIndex($table, ['bumped_at', 'id'])) {
                    $t->index(['bumped_at', 'id']);
                }
            });
            Schema::table($table, function (Blueprint $t) use ($table, $dead) {
                if (Schema::hasIndex($table, ['group_id', 'updated_at'])) {
                    $t->dropIndex(['group_id', 'updated_at']);
                }
                if (Schema::hasColumn($table, $dead)) {
                    $t->dropColumn($dead);
                }
            });
        }
    }

    /** The rebuild drops the table, and with it the AUTOINCREMENT high-water mark; a deleted id must not come back. */
    private function sqliteSequence(string $table): ?int
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return null;
        }
        $seq = DB::table('sqlite_sequence')->where('name', $table)->value('seq');

        return $seq === null ? null : (int) $seq;
    }

    private function restoreSqliteSequence(string $table, ?int $sequence): void
    {
        if ($sequence === null) {
            return;
        }
        DB::table('sqlite_sequence')->where('name', $table)->delete();
        DB::table('sqlite_sequence')->insert(['name' => $table, 'seq' => $sequence]);
    }

    public function down(): void
    {
        foreach (self::BOARDS as $table => [, , $dead]) {
            // The old code orders by updated_at, so a comment made since the upgrade is folded back into it.
            DB::update("UPDATE {$table} SET updated_at = CASE WHEN updated_at IS NULL OR bumped_at > updated_at THEN bumped_at ELSE updated_at END");
            Schema::table($table, function (Blueprint $t) use ($dead) {
                $t->timestamp($dead)->nullable();
                $t->index(['group_id', 'updated_at']);
            });
            Schema::table($table, function (Blueprint $t) {
                $t->dropIndex(['group_id', 'bumped_at']);
                $t->dropIndex(['bumped_at', 'id']);
                $t->dropColumn(['bumped_at', 'edited_at']);
            });
        }
    }
};
