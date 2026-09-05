<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * These DB defaults are only a backstop: every membership path snapshots the group's latest tuple
 * instead (docs/internals/group-talk.md, "The cursor is snapshotted, not defaulted").
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
