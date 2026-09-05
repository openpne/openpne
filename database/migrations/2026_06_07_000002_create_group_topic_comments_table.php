<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_topic_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_topic_id')->constrained('group_topics')->cascadeOnDelete();
            // OpenPNE 3 keeps a comment when its author is deleted (Member onDelete: set null).
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->unsignedInteger('number');
            // TEXT (not VARCHAR): OpenPNE 3 comment body is Doctrine `type: string` = MySQL TEXT
            // with no validator length limit, so migrated long comments must not be truncated.
            $table->text('body');
            $table->timestamps();

            // Not unique: `number` is a racy max+1 and migrated data may carry duplicates
            // (docs/internals/group-boards.md, "Comment threads page by id").
            $table->index(['group_topic_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_topic_comments');
    }
};
