<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Lets a talk message carry a link card, on the same two columns the other bodies carry one on
 * (2026_08_06_000002_add_link_card_to_body_tables). The "messages" that migration names as
 * deliberately absent are direct messages, which this does not change: `group_messages` did not
 * exist when it was written.
 *
 * The SQLite index is the same exception 2026_08_07_000001 makes: InnoDB backs a foreign key with an
 * index and SQLite backs it with nothing, so without this the prune sweep's "does any body still
 * point at this card" degrades into a full scan of the talk table there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_messages', function (Blueprint $table): void {
            $table->foreignId('link_card_id')->nullable()->constrained('link_cards')->nullOnDelete();
            $table->timestamp('link_card_synced_at')->nullable();
        });

        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('group_messages', function (Blueprint $table): void {
                $table->index('link_card_id');
            });
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('group_messages', function (Blueprint $table): void {
                $table->dropIndex(['link_card_id']);
            });
        }

        Schema::table('group_messages', function (Blueprint $table): void {
            // The constraint goes before the column: InnoDB adopts the index it created for the
            // foreign key, and refuses to drop it while the constraint still exists (errno 1553).
            $table->dropForeign(['link_card_id']);
            $table->dropColumn(['link_card_id', 'link_card_synced_at']);
        });
    }
};
