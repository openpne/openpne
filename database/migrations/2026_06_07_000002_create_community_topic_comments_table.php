<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Comments on a community topic (OpenPNE 3 `community_topic_comment`): the numbered replies of a
 * thread, mirroring the diary_comments shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_topic_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('community_topic_id')->constrained('community_topics')->cascadeOnDelete();
            // OpenPNE 3 keeps a comment when its author is deleted (Member onDelete: set null).
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            // Per-topic sequence (OpenPNE 3 number), rendered as "3:" and used for chronological
            // ordering. Assigned max(number)+1 per topic at create time.
            $table->unsignedInteger('number');
            // TEXT (not VARCHAR): OpenPNE 3 comment body is Doctrine `type: string` = MySQL TEXT
            // with no validator length limit, so migrated long comments must not be truncated.
            $table->text('body');
            $table->timestamps();

            // Non-unique, matching OpenPNE 3's community_topic_id index: its `number` is a racy
            // max+1, so legacy data can carry duplicate (topic, number) and must import losslessly.
            // New comments are serialized by the topic-row lock in CreateTopicComment. Drives the
            // thread query: WHERE community_topic_id=? ORDER BY number.
            $table->index(['community_topic_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_topic_comments');
    }
};
