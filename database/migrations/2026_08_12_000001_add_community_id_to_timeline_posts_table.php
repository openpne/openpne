<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timeline_posts', function (Blueprint $table) {
            $table->foreignId('community_id')->nullable()->constrained('groups')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('timeline_posts', function (Blueprint $table) {
            $table->dropForeign(['community_id']);
            $table->dropColumn('community_id');
        });
    }
};
