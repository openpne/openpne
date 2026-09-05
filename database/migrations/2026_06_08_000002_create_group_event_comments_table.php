<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_event_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_event_id')->constrained('group_events')->cascadeOnDelete();
            // OpenPNE 3 keeps a comment when its author is deleted (Member onDelete: set null).
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->unsignedInteger('number');
            $table->text('body');
            $table->timestamps();

            // Not unique: `number` is a racy max+1 and migrated data may carry duplicates
            // (docs/internals/group-boards.md, "Comment threads page by id").
            $table->index(['group_event_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_event_comments');
    }
};
