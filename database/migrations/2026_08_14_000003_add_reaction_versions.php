<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * How a reader's open tab learns that a message it already holds has changed. The talk poll asks for
 * rows *after* a position, so a reaction on an older message is invisible to it: nothing about that
 * row's (created_at, id) moved.
 *
 * So the group carries a counter and each touched message records the value it was given
 * (App\Features\GroupTalk\TalkReactionVersion). A timestamp would not do: a MySQL timestamp is
 * second-precise, so a strict `>` watermark would drop everything that shared its second. A counter
 * incremented under the group row's lock is unique and monotonic within the group by construction.
 *
 * Nullable, not 0: a message nobody has reacted to has no version, and only a version the counter
 * actually issued can be compared.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table): void {
            $table->unsignedBigInteger('talk_reaction_seq')->default(0);
        });

        Schema::table('group_messages', function (Blueprint $table): void {
            $table->unsignedBigInteger('reactions_version')->nullable();
            // The poll's read: this group's touched rows, in version order.
            $table->index(['group_id', 'reactions_version']);
        });
    }

    public function down(): void
    {
        Schema::table('group_messages', function (Blueprint $table): void {
            $table->dropIndex(['group_id', 'reactions_version']);
        });

        Schema::table('group_messages', function (Blueprint $table): void {
            $table->dropColumn('reactions_version');
        });

        Schema::table('groups', function (Blueprint $table): void {
            $table->dropColumn('talk_reaction_seq');
        });
    }
};
