<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('admin_users')->whereNull('password')->exists()) {
            throw new RuntimeException('admin_users holds administrators without a password; delete them before rolling back.');
        }

        Schema::table('admin_users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });
    }
};
