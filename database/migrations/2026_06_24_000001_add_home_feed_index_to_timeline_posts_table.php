<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * `created_at` leads so InnoDB does not adopt this index to back the self-FK and then refuse to
 * drop it (errno 1553).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timeline_posts', function (Blueprint $table) {
            $table->index(['created_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('timeline_posts', function (Blueprint $table) {
            $table->dropIndex(['created_at', 'id']);
        });
    }
};
