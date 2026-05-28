<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('friend_requests', function (Blueprint $table) {
            $table->foreignId('requester_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('target_id')->constrained('members')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['requester_id', 'target_id']);
            $table->index('target_id');
        });

        $this->addPairwiseDistinctConstraint('friend_requests', 'requester_id', 'target_id');
    }

    public function down(): void
    {
        $this->dropPairwiseDistinctConstraint('friend_requests');
        Schema::dropIfExists('friend_requests');
    }

    protected function addPairwiseDistinctConstraint(string $table, string $a, string $b): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            DB::unprepared(sprintf(
                'CREATE TRIGGER %1$s_distinct_insert BEFORE INSERT ON %1$s
                 FOR EACH ROW WHEN NEW.%2$s = NEW.%3$s
                 BEGIN SELECT RAISE(ABORT, \'%1$s.%2$s must differ from %1$s.%3$s\'); END;
                 CREATE TRIGGER %1$s_distinct_update BEFORE UPDATE ON %1$s
                 FOR EACH ROW WHEN NEW.%2$s = NEW.%3$s
                 BEGIN SELECT RAISE(ABORT, \'%1$s.%2$s must differ from %1$s.%3$s\'); END;',
                $table, $a, $b
            ));
        } else {
            DB::statement(sprintf(
                'ALTER TABLE `%1$s` ADD CONSTRAINT `chk_%1$s_distinct` CHECK (`%2$s` <> `%3$s`)',
                $table, $a, $b
            ));
        }
    }

    protected function dropPairwiseDistinctConstraint(string $table): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            DB::unprepared(sprintf(
                'DROP TRIGGER IF EXISTS %1$s_distinct_insert;
                 DROP TRIGGER IF EXISTS %1$s_distinct_update;',
                $table
            ));
        } else {
            DB::statement(sprintf(
                'ALTER TABLE `%1$s` DROP CONSTRAINT `chk_%1$s_distinct`',
                $table
            ));
        }
    }
};
