<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The site-wide posting-time axis of the boards and the calendar axis of the events
 * (docs/internals/ordering.md, "One index per axis").
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['group_topics', 'group_events'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->index(['created_at', 'id']);
            });
        }
        Schema::table('group_events', function (Blueprint $t) {
            $t->index(['open_date', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('group_events', function (Blueprint $t) {
            $t->dropIndex(['open_date', 'id']);
        });
        foreach (['group_topics', 'group_events'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropIndex(['created_at', 'id']);
            });
        }
    }
};
