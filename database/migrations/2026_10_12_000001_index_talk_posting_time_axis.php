<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The site-wide posting-time axis of talk, which the home issue's window cuts once a day
 * (docs/internals/ordering.md, "One index per axis").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_messages', function (Blueprint $table) {
            $table->index(['created_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('group_messages', function (Blueprint $table) {
            $table->dropIndex(['created_at', 'id']);
        });
    }
};
