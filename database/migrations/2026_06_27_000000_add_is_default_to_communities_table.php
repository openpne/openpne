<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * communities.is_default (OpenPNE 3 community_config[is_default]): marks an "everyone" community that
 * new members should join. No index — boolean selectivity is poor and a leading-column index would
 * invite the MySQL FK 1553 friction; the admin reads it per row. Auto-join-on-registration is deferred;
 * this column plus the admin "add all members" action are the first step.
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
