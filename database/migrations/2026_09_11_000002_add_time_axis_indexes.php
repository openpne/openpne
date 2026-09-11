<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The site-wide posting-time axis of each list (docs/internals/ordering.md, "One index per axis");
 * thread_id backed no query and no foreign key.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['diaries', 'members', 'groups'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->index(['created_at', 'id']);
            });
        }
        Schema::table('direct_messages', function (Blueprint $t) {
            $t->dropIndex(['thread_id']);
        });
    }

    public function down(): void
    {
        Schema::table('direct_messages', function (Blueprint $t) {
            $t->index('thread_id');
        });
        foreach (['diaries', 'members', 'groups'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropIndex(['created_at', 'id']);
            });
        }
    }
};
