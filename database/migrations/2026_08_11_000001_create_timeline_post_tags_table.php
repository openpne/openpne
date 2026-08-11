<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The #hashtags inside a timeline post's body: the half-open range [offset, offset+length) over the
 * body in Unicode code points, exactly as timeline_post_mentions records a mention, and true forever
 * for the same reason — a post is never edited.
 *
 * There is no tag entity to point at, so the row carries the resolved value instead of a foreign
 * key: `tag` is the body's text put through NFKC and lowercased by
 * App\Features\Timeline\HashtagParser, which is what a tag page looks a post up by. The range still
 * describes the raw body, so a reader sees what was typed. No timestamps: a tag is part of the post,
 * which carries them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timeline_post_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('timeline_post_id')->constrained('timeline_posts')->cascadeOnDelete();
            // The parser caps a tag at 30 code points. The headroom keeps a later cap change a
            // parser change rather than a migration, and 64 still leaves the index below an order of
            // magnitude inside InnoDB's key length limit.
            $table->string('tag', 64);
            $table->unsignedSmallInteger('offset');
            $table->unsignedSmallInteger('length');

            // Reads a post's tags, and keeps two rows off one start offset. Leads with
            // timeline_post_id, so it also backs that FK.
            $table->unique(['timeline_post_id', 'offset']);

            // The tag page's lookup. Leads with `tag` — an index starting at the FK column can be
            // adopted as that constraint's backing index and then refuse to drop (MySQL errno 1553).
            $table->index(['tag', 'timeline_post_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timeline_post_tags');
    }
};
