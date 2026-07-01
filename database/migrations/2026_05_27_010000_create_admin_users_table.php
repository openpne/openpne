<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            // Administrators log in by username, not email — OpenPNE 3 has no
            // administrator email column. The unique constraint makes the
            // username the login identifier so accounts carried over from
            // OpenPNE 3 migrate as-is.
            $table->string('username', 64)->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_users');
    }
};
