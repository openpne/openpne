<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * InnoDB adopts (member_id, diary_id) to back the member_id foreign key, so down() re-adds a
 * standalone member_id index before dropping the composite and leaves it there (errno 1553).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('diary_comments', function (Blueprint $table) {
            $table->index(['member_id', 'diary_id']);
        });
    }

    public function down(): void
    {
        Schema::table('diary_comments', function (Blueprint $table) {
            $table->index('member_id');
            $table->dropIndex(['member_id', 'diary_id']);
        });
    }
};
