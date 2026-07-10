<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // AvatarColor slug for the no-image badge; null = neutral. A plain string (not an enum
        // column) so a later free-color tier can store #rrggbb literals without a migration.
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
