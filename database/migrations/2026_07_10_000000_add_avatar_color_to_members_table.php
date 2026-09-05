<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // AvatarColor slug; null = neutral.
        Schema::table('members', function (Blueprint $table) {
            $table->string('avatar_color')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('avatar_color');
        });
    }
};
