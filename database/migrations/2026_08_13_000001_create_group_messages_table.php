<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Group talk messages: one row per utterance in a group's linear chat. The successor of the
 * community timeline, but not its shape — a message carries no visibility column, because who may
 * read it is answered by the group (App\Features\GroupTalk\GroupTalkAccess), never per row.
 *
 * Ordering is the (created_at, id) tuple, not id alone: migrated rows are inserted in transfer
 * order, not chronological order, so id is not monotonic in time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
            // Keep the message when its author is deleted (OpenPNE 3 opCommunityTopicPlugin sets its
            // Member relations null), so the conversation stays intact and the author reads as a
            // withdrawn member. Group content survives its author; personal content does not.
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            // Lineage only: what a migrated OpenPNE 3 activity reply or OpenPNE 4 timeline reply
            // pointed at. The composer never writes it — talk has no reply UI. nullOnDelete, unlike
            // timeline_posts' cascade: lineage records where a message came from, it does not bind
            // one message's life to another's.
            $table->foreignId('in_reply_to_id')->nullable();
            $table->text('body');
            $table->timestamps();

            $table->foreign('in_reply_to_id')->references('id')->on('group_messages')->nullOnDelete();
            // The chat read (a group's messages, newest tuple first) and the unread count that
            // follows it. No index leads with in_reply_to_id: InnoDB would adopt it as the FK's
            // backing index and then refuse to drop it (error 1553).
            $table->index(['group_id', 'created_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_messages');
    }
};
