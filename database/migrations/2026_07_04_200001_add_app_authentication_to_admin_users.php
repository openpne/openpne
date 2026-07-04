<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Filament's App (TOTP) multi-factor authentication store for an administrator.
        // Both are Filament `encrypted` casts (ciphertext is longer than the raw value),
        // and null = MFA not set up (opt-in). Recovery codes are also bcrypt-hashed by
        // Filament before this encrypted column.
        Schema::table('admin_users', function (Blueprint $table) {
            $table->text('app_authentication_secret')->nullable()->after('password_scheme');
            $table->text('app_authentication_recovery_codes')->nullable()->after('app_authentication_secret');
        });
    }

    public function down(): void
    {
        Schema::table('admin_users', function (Blueprint $table) {
            $table->dropColumn(['app_authentication_secret', 'app_authentication_recovery_codes']);
        });
    }
};
