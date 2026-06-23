<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The cross-member home feed reads ORDER BY created_at DESC, id DESC LIMIT n (in_reply_to_id IS NULL
 * applied as a residual filter). This index lets MySQL satisfy that order by scanning it descending
 * and stop at the limit, instead of a full scan + filesort as the table grows. created_at leads
 * (not in_reply_to_id) so InnoDB does not adopt it to back the self-FK — an in_reply_to_id-leading
 * index gets adopted and then cannot be dropped (MySQL error 1553).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timeline_posts', function (Blueprint $table) {
            $table->index(['created_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('timeline_posts', function (Blueprint $table) {
            $table->dropIndex(['created_at', 'id']);
        });
    }
};
