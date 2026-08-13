<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The talk read cursor and mute flag, on the membership row rather than in a table of their own:
 * "membership implies cursor" then holds by the row's existence, so a non-member reader cannot
 * accumulate unread state at all.
 *
 * The cursor is the (created_at, id) tuple of the last message read, stored as copied values — no
 * FK on the id, so deleting that message is a no-op for the cursor. These DB defaults are only a
 * backstop: a MySQL timestamp is second-precise, so a join and a message in the same second would
 * compare wrong. Every membership path snapshots the group's real latest tuple instead; that helper
 * and the unread reads arrive with the unread PR.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_members', function (Blueprint $table): void {
            $table->timestamp('talk_read_at')->useCurrent();
            $table->unsignedBigInteger('talk_read_message_id')->default(0);
            $table->boolean('is_talk_muted')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('group_members', function (Blueprint $table): void {
            $table->dropColumn(['talk_read_at', 'talk_read_message_id', 'is_talk_muted']);
        });
    }
};
