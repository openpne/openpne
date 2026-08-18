<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * `group_messages.in_reply_to_id` becomes the reply reference the room draws, and that is why the
 * foreign key goes (docs/internals/group-talk.md#replies).
 *
 * `nullOnDelete` would erase the reference the moment the answered message was deleted, and "this
 * answers nothing" and "the message this answers is gone" are two different things on screen. So a
 * dangling id is a *meaningful* state rather than a leak — nothing collects it — and every writer
 * validates a live same-group parent in place of the engine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_messages', function (Blueprint $table): void {
            // Named by column: SQLite's grammar refuses a foreign key named as a string, and rebuilds
            // the table for this form.
            $table->dropForeign(['in_reply_to_id']);
        });

        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        // InnoDB's backing index outlives the constraint it was created for, and nothing reads
        // in_reply_to_id leading. After the key, never before (errno 1553); SQLite never had one.
        Schema::table('group_messages', function (Blueprint $table): void {
            $table->dropIndex('group_messages_in_reply_to_id_foreign');
        });
    }

    public function down(): void
    {
        // The constraint cannot come back over references the promotion made legal. Selected, then
        // updated by key: MySQL refuses a subquery naming the table being updated.
        do {
            $dangling = DB::table('group_messages')
                ->whereNotNull('in_reply_to_id')
                ->whereNotIn('in_reply_to_id', fn (Builder $live) => $live->select('id')->from('group_messages'))
                ->limit(1000)
                ->pluck('id');

            DB::table('group_messages')->whereIn('id', $dangling)->update(['in_reply_to_id' => null]);
        } while ($dangling->isNotEmpty());

        Schema::table('group_messages', function (Blueprint $table): void {
            $table->foreign('in_reply_to_id')->references('id')->on('group_messages')->nullOnDelete();
        });
    }
};
