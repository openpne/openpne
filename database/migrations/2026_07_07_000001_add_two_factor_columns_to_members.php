<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fortify's standard TOTP two-factor columns for a member. Fortify's actions encrypt the
        // secret and the recovery-code JSON before writing (no model cast — a cast would double
        // encrypt). null = MFA not set up (opt-in); secret set with a null two_factor_confirmed_at
        // is a pending set-up, which never gates login (confirm mode).
        Schema::table('members', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('password_scheme');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });
    }
};
