<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_password_setup_tokens', function (Blueprint $table) {
            $table->foreignId('admin_user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->dateTime('expires_at');
            $table->dateTime('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_password_setup_tokens');
    }
};
