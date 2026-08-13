<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Group topics (successor of the OpenPNE 3 opCommunityTopicPlugin `community_topic` table):
 * the threads of a group's bulletin board.
 */
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
            // OpenPNE 3 community_topic.name/body are Doctrine `type: string` (no length) = MySQL
            // TEXT, with no validator length limit. TEXT (not VARCHAR) so migrated content is not
            // truncated or locked out of re-editing.
            $table->text('name');
            $table->text('body');
            // "Last activity" timestamp OpenPNE 3 bumps on a content edit or a new comment. The
            // board itself orders by updated_at (see index); OpenPNE 3's sidebar / API "latest
            // topics" widgets order by this. Carried for upgrade fidelity. Nullable: OpenPNE 3
            // leaves it null until the first bump.
            $table->dateTime('topic_updated_at')->nullable();
            $table->timestamps();

            // Board query: WHERE group_id=? ORDER BY updated_at DESC (a new comment touches the
            // parent topic's updated_at, so the most recently active thread sorts first).
            $table->index(['group_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_topics');
    }
};
