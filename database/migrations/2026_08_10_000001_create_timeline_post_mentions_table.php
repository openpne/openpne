<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The @mentions inside a timeline post's body. Each row is the half-open range
 * [offset, offset+length) over the body, counted in Unicode code points — the unit PHP's mb_substr
 * and JavaScript's Array.from() agree on, so the picker's selection and the server's check measure
 * the same thing. The range is recorded once at post time from the picker's selection; posts are
 * never edited, so a stored range describes the body forever.
 *
 * Deleting the member cascades their mention rows away, which leaves the range rendering as the
 * plain text it already is — nothing downstream has to recognise a dangling mention. No timestamps:
 * a mention is part of the post, which carries them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timeline_post_mentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('timeline_post_id')->constrained('timeline_posts')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->unsignedSmallInteger('offset');
            $table->unsignedSmallInteger('length');

            // Reads a post's mentions, and keeps two rows off one start offset — the slice of
            // App\Features\Timeline\Actions\ResolveMentions' non-overlap invariant an index can
            // hold. Leads with timeline_post_id, so it also backs that FK.
            $table->unique(['timeline_post_id', 'offset']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timeline_post_mentions');
    }
};
