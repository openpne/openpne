<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * communities.is_default (OpenPNE 3 community_config[is_default]): marks an "everyone" community.
 * Carried for OpenPNE 3 fidelity; shown and toggled in the admin communities table. No index —
 * boolean selectivity is poor and a leading-column index would invite the MySQL FK 1553 friction;
 * the admin reads it per row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communities', function (Blueprint $table): void {
            $table->boolean('is_default')->default(false)->after('register_policy');
        });
    }

    public function down(): void
    {
        Schema::table('communities', function (Blueprint $table): void {
            $table->dropColumn('is_default');
        });
    }
};
