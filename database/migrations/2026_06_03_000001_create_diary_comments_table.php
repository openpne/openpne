<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diary_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('diary_id')->constrained('diaries')->cascadeOnDelete();
            // OpenPNE 3 keeps a comment when its author is deleted (Member onDelete: set null),
            // so the thread stays intact and the comment shows as a withdrawn member.
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            // Per-diary sequence (OpenPNE 3 DiaryComment.number), rendered as "3:" and used for
            // chronological ordering. Assigned max(number)+1 per diary at create time.
            $table->unsignedInteger('number');
            // TEXT (not VARCHAR): OpenPNE 3 comment body is Doctrine `type: string` = MySQL TEXT
            // with no validator length limit, so migrated long comments must not be truncated.
            $table->text('body');
            $table->timestamps();

            // Drives the per-diary thread query: WHERE diary_id=? ORDER BY number.
            $table->index(['diary_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diary_comments');
    }
};
