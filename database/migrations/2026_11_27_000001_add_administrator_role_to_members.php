<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dateTime('admin_granted_at')->nullable()->index();
            $table->unsignedInteger('admin_generation')->default(0);
            $table->dateTime('withdrawal_started_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropIndex(['admin_granted_at']);
            $table->dropColumn(['admin_granted_at', 'admin_generation', 'withdrawal_started_at']);
        });
    }
};
