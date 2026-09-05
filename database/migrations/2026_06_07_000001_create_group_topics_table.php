<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_topics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
            // Keep the topic when its author is deleted (OpenPNE 3 Member onDelete: set null), so
            // the board stays intact and the thread shows as a withdrawn member.
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            // OpenPNE 3 community_topic.name/body are Doctrine `type: string` with no length = MySQL
            // TEXT, so TEXT here keeps migrated content from being truncated.
            $table->text('name');
            $table->text('body');
            // OpenPNE 3's "last activity" bump, carried for upgrade fidelity and null until OpenPNE 3
            // first bumps it; the board here orders by updated_at.
            $table->dateTime('topic_updated_at')->nullable();
            $table->timestamps();

            $table->index(['group_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_topics');
    }
};
