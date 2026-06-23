<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The cross-member home feed reads WHERE in_reply_to_id IS NULL ORDER BY created_at DESC, id DESC.
 * Without a matching index that is a full scan + filesort as the table grows; this index lets the
 * leading in_reply_to_id IS NULL fix the prefix and the rest satisfy the ordering (id is the tiebreaker).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timeline_posts', function (Blueprint $table) {
            $table->index(['in_reply_to_id', 'created_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('timeline_posts', function (Blueprint $table) {
            $table->dropIndex(['in_reply_to_id', 'created_at', 'id']);
        });
    }
};
