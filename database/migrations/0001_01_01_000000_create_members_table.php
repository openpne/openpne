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
            // Nullable so an OpenPNE 3 member without a usable address upgrades to a login-impossible
            // row; unique still admits many NULLs (NULLs are distinct) on both MySQL and SQLite.
            $table->string('email')->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            // Copied verbatim from OpenPNE 3 member.is_login_rejected, so a banned member stays banned.
            $table->boolean('is_login_rejected')->default(false);
            $table->unsignedTinyInteger('profile_visibility')->default(1); // Visibility::Members
            // A column rather than a member_preferences row because it is read in middleware on
            // every member-facing request.
            $table->string('locale')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        // Laravel's database session handler writes the authenticated id to a hard-coded `user_id`
        // column (DatabaseSessionHandler::addUserInformation), so the column keeps the framework
        // name and has no FK to members.
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
