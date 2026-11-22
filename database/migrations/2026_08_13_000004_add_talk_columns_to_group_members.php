<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * These DB defaults are only a backstop: every membership path snapshots the group's latest tuple
 * instead (docs/internals/group-talk.md, "The cursor is snapshotted, not defaulted").
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQLite before 3.32.0 refuses ADD COLUMN with a non-constant default even on an empty table, which the rebuild behind change() avoids.
        $rebuild = DB::connection()->getDriverName() === 'sqlite'
            && version_compare(DB::connection()->getServerVersion(), '3.32.0', '<');

        Schema::table('group_members', function (Blueprint $table) use ($rebuild): void {
            $readAt = $table->dateTime('talk_read_at');
            $rebuild ? $readAt->nullable() : $readAt->useCurrent();
            $table->unsignedBigInteger('talk_read_message_id')->default(0);
            $table->boolean('is_talk_muted')->default(false);
        });

        if ($rebuild) {
            Schema::table('group_members', function (Blueprint $table): void {
                $table->dateTime('talk_read_at')->useCurrent()->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('group_members', function (Blueprint $table): void {
            $table->dropColumn(['talk_read_at', 'talk_read_message_id', 'is_talk_muted']);
        });
    }
};
