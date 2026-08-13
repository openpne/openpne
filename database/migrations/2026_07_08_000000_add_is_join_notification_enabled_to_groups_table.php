<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * groups.is_join_notification_enabled (OpenPNE 3 community_config[is_send_pc_joinCommunity_mail]):
 * whether the community's admins are told when a member joins. Default true — OpenPNE 3 treated an absent
 * value as on. No index: boolean selectivity is poor and the flag is read per community, not scanned.
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
