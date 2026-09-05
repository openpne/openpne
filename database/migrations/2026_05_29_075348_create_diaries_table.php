<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            // OpenPNE 3 diary.title/body are Doctrine `type: string` with no length = MySQL TEXT, so
            // TEXT here keeps migrated long content from being truncated.
            $table->text('title');
            $table->text('body');
            $table->unsignedTinyInteger('visibility')->default(1); // Visibility::Members
            $table->timestamps();

            $table->index(['member_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diaries');
    }
};
