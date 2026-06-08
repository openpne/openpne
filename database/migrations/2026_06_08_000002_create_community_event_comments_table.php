<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Comments on a community event (OpenPNE 3 `community_event_comment`): the numbered replies of an
 * event's thread, identical in shape to community_topic_comments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_event_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('community_event_id')->constrained('community_events')->cascadeOnDelete();
            // OpenPNE 3 keeps a comment when its author is deleted (Member onDelete: set null).
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            // Per-event sequence (OpenPNE 3 number), assigned max(number)+1 per event at create time.
            $table->unsignedInteger('number');
            $table->text('body');
            $table->timestamps();

            // Non-unique, matching OpenPNE 3's community_event_id index: `number` is a racy max+1 so
            // legacy data can carry duplicate (event, number) and must import losslessly. New
            // comments are serialized by the event-row lock in CreateEventComment. Drives the thread
            // query: WHERE community_event_id=? ORDER BY number.
            $table->index(['community_event_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_event_comments');
    }
};
