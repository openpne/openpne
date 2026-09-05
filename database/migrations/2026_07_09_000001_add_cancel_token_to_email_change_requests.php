<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Nullable because a change already in flight when this migration runs has no cancel token and its
 * sent notice carried no cancel link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_change_requests', function (Blueprint $table) {
            $table->string('cancel_token')->nullable()->unique()->after('token');
        });
    }

    public function down(): void
    {
        Schema::table('email_change_requests', function (Blueprint $table) {
            // Drop the unique index before the column: SQLite cannot drop a column an index still references.
            $table->dropUnique(['cancel_token']);
            $table->dropColumn('cancel_token');
        });
    }
};
