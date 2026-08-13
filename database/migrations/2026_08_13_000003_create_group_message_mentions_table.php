<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The @mentions inside a talk message's body, shaped exactly like timeline_post_mentions: each row
 * is the half-open range [offset, offset+length) over the body, counted in Unicode code points —
 * the unit PHP's mb_substr and JavaScript's Array.from() agree on. Messages are never edited, so a
 * stored range describes the body forever.
 *
 * Deleting the member cascades their mention rows away, which leaves the range rendering as the
 * plain text it already is. The author survives a withdrawal (group_messages.member_id is
 * nullOnDelete); a mention of that member does not — different column, different contract. No
 * timestamps: a mention is part of the message, which carries them.
 *
 * Nothing writes here yet — the mention composer arrives with its own PR, and the timeline transfer
 * fills these rows for migrated content.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_message_mentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_message_id')->constrained('group_messages')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->unsignedSmallInteger('offset');
            $table->unsignedSmallInteger('length');

            // Reads a message's mentions, and keeps two rows off one start offset — the slice of the
            // resolver's non-overlap invariant an index can hold. Leads with group_message_id, so it
            // also backs that FK.
            $table->unique(['group_message_id', 'offset']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_message_mentions');
    }
};
