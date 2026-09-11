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
        foreach (self::BOARDS as $table => [$comments, $fk, $dead]) {
            Schema::table($table, function (Blueprint $t) {
                $t->dateTime('bumped_at')->nullable();
                $t->dateTime('edited_at')->nullable();
            });

            $untimed = DB::table($table)->whereNull('created_at')->count();
            if ($untimed > 0) {
                throw new RuntimeException("{$table}: {$untimed} row(s) have no created_at; bumped_at needs a time to fall back to.");
            }

            DB::update("UPDATE {$table} SET bumped_at = COALESCE((SELECT MAX(c.created_at) FROM {$comments} AS c WHERE c.{$fk} = {$table}.id), created_at)");

            // change() rebuilds the table on SQLite, safe only because neither table has a composite primary key or a unique index.
            Schema::table($table, function (Blueprint $t) {
                $t->dateTime('bumped_at')->nullable(false)->change();
            });

            // Add before drop: InnoDB backs the group_id foreign key with whichever of these indexes exists (errno 1553).
            Schema::table($table, function (Blueprint $t) {
                $t->index(['group_id', 'bumped_at']);
                $t->index(['bumped_at', 'id']);
            });
            Schema::table($table, function (Blueprint $t) use ($dead) {
                $t->dropIndex(['group_id', 'updated_at']);
                $t->dropColumn($dead);
            });
        }
    }

    public function down(): void
    {
        foreach (self::BOARDS as $table => [, , $dead]) {
            Schema::table($table, function (Blueprint $t) use ($dead) {
                $t->dateTime($dead)->nullable();
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
