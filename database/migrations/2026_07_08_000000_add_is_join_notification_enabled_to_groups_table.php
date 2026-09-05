<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Default true because OpenPNE 3 treated an absent is_send_pc_joinCommunity_mail as on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table): void {
            $table->boolean('is_join_notification_enabled')->default(true)->after('is_default');
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table): void {
            $table->dropColumn('is_join_notification_enabled');
        });
    }
};
