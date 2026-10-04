<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, array{string, string}|null> table => the pair its rows keep distinct */
    private const TABLES = [
        'friendships' => ['member_id', 'friend_id'],
        'friend_requests' => ['requester_id', 'target_id'],
        'member_blocks' => ['blocker_id', 'blocked_id'],
        'group_join_requests' => null,
    ];

    public function up(): void
    {
        foreach (self::TABLES as $name => $pair) {
            Schema::table($name, function (Blueprint $table) {
                $table->timestamp('created_at')->change();
            });
            $this->recreateDistinctTriggers($name, $pair);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name => $pair) {
            Schema::table($name, function (Blueprint $table) {
                $table->timestamp('created_at')->useCurrent()->change();
            });
            $this->recreateDistinctTriggers($name, $pair);
        }
    }

    /**
     * SQLite cannot alter a column, so change() rebuilds the table, and the rebuild drops the
     * triggers the create migration added; MySQL alters in place and keeps its CHECK constraint.
     *
     * @param  array{string, string}|null  $pair
     */
    private function recreateDistinctTriggers(string $table, ?array $pair): void
    {
        if ($pair === null || Schema::getConnection()->getDriverName() !== 'sqlite') {
            return;
        }

        [$a, $b] = $pair;
        DB::unprepared(sprintf(
            'DROP TRIGGER IF EXISTS %1$s_distinct_insert;
             DROP TRIGGER IF EXISTS %1$s_distinct_update;
             CREATE TRIGGER %1$s_distinct_insert BEFORE INSERT ON %1$s
             FOR EACH ROW WHEN NEW.%2$s = NEW.%3$s
             BEGIN SELECT RAISE(ABORT, \'%1$s.%2$s must differ from %1$s.%3$s\'); END;
             CREATE TRIGGER %1$s_distinct_update BEFORE UPDATE ON %1$s
             FOR EACH ROW WHEN NEW.%2$s = NEW.%3$s
             BEGIN SELECT RAISE(ABORT, \'%1$s.%2$s must differ from %1$s.%3$s\'); END;',
            $table, $a, $b
        ));
    }
};
