<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mirror of `sessions` (0001_01_01_000000): the admin surface keeps its own
        // session store (UseAdminSessionStore). `user_id` holds admin_users ids — the
        // database handler stamps the container's default guard, which the middleware
        // pins to `admin` on admin-surface requests. Framework column name, no FK.
        Schema::create('admin_sessions', function (Blueprint $table) {
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
        Schema::dropIfExists('admin_sessions');
    }
};
