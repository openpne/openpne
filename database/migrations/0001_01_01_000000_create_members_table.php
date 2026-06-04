<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Nullable so a member upgraded from OpenPNE 3 without a usable address/credential
            // is kept as a login-impossible row. Unique still admits many NULLs (NULLs are
            // distinct) on both MySQL and SQLite.
            $table->string('email')->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            // Whether this member's profile page is reachable, on the App\Support\Visibility
            // scale: Open = web-public (guests may view it), otherwise login-required. Default
            // Members keeps profiles SNS-internal until the member opts into web-public.
            $table->unsignedTinyInteger('profile_visibility')->default(1); // Visibility::Members
            // Member-facing UI language (one of SetLocale::SUPPORTED_LOCALES) or null to fall
            // back to the session/Accept-Language chain. A typed column, not a member_preferences
            // row, because it is read in middleware on every member-facing request and is an
            // identity-ish attribute (cf. email/password) rather than a feature preference.
            $table->string('locale')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        // Laravel's database session handler writes the authenticated id to a
        // hard-coded `user_id` column (DatabaseSessionHandler::addUserInformation),
        // so this column keeps its framework name even though the authenticatable
        // is a Member. There is no FK to the members table.
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
