<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Timeline posts (successor of OpenPNE 3 `activity_data`, opTimelinePlugin). A single
 * self-referential table: a reply is a post row with in_reply_to_id set, so the feed reads
 * top-level rows (in_reply_to_id IS NULL) and a thread reads a parent's replies. OpenPNE 3
 * public_flag (0=OPEN/1=SNS/2=FRIEND/3=PRIVATE) maps 1:1 onto Visibility.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timeline_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            // A reply points at its parent post; deleting the parent cascades its replies.
            $table->foreignId('in_reply_to_id')->nullable();
            // OpenPNE 3 activity_data.body is string(140); keep the 140-char cap (the compose
            // form enforces it too). URLs are stored verbatim and linked at render time.
            $table->string('body', 140);
            // Restriction level: Open=0 < Members=1 < Friends=2 < Private=3 (monotonic).
            // OpenPNE 3 default public_flag is SNS=1 (Members). Replies copy their parent's value.
            $table->unsignedTinyInteger('visibility')->default(1); // Visibility::Members
            $table->timestamps();

            $table->foreign('in_reply_to_id')->references('id')->on('timeline_posts')->cascadeOnDelete();
            // Member timeline: WHERE member_id=? AND in_reply_to_id IS NULL ORDER BY created_at DESC.
            $table->index(['member_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timeline_posts');
    }
};
