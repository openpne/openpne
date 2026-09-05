<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timeline_post_mentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('timeline_post_id')->constrained('timeline_posts')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->unsignedSmallInteger('offset');
            $table->unsignedSmallInteger('length');

            // Holds the part of the mention non-overlap invariant an index can, and leads with
            // timeline_post_id so it also backs that FK.
            $table->unique(['timeline_post_id', 'offset']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timeline_post_mentions');
    }
};
