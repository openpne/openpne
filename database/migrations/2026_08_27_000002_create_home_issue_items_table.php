<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The ledger of what an issue featured: a section, a rank inside it, and a reference to the source —
 * never a copy of it. The page re-resolves every row from its source at render time through that
 * source's own visibility gate, so an issue shows what the reader may see now rather than what was
 * true the night it was built.
 *
 * `source_id` therefore carries no foreign key, and these rows are NOT swept when the source is
 * deleted — the opposite of what `reactions` chose, from the same premise. A dangling reference
 * renders as nothing (the page silently drops what it cannot resolve), and it is exactly what the
 * never-feature-again rule has to remember: sweeping it would let a deleted-and-reposted item lead
 * the page a second time.
 *
 * `score` and `stats` are ranking provenance — why this row outranked another on the night it was
 * chosen. They are never displayed and never re-read as current truth.
 *
 * No standalone `home_issue_id` index: it leads the (home_issue_id, section, rank) unique and both
 * engines take a leftmost prefix, InnoDB by adopting that unique as the foreign key's backing index
 * and SQLite by searching it for the cascade. That adoption is also why any later migration dropping
 * the unique must drop the foreign key first (MySQL errno 1553). `reactions` declares an explicit
 * index only because its `member_id` sits in the middle of its unique key, where no prefix reaches.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('home_issue_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('home_issue_id')->constrained('home_issues')->cascadeOnDelete();
            $table->string('section', 24);
            $table->unsignedSmallInteger('rank');
            // Same width as reactions.reactable_type: both hold a morph alias from the one map.
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->unsignedInteger('score');
            $table->json('stats');
            $table->timestamps();

            // The section's running order, and the shape the page reads it back in.
            $table->unique(['home_issue_id', 'section', 'rank']);
            // One issue never features the same source twice in the same section. Named explicitly
            // because the generated name runs to 67 characters, past MySQL's 64-character identifier
            // limit (errno 1059) — which SQLite does not enforce, so the default would migrate
            // cleanly on one lane and fail on the other.
            $table->unique(['home_issue_id', 'section', 'source_type', 'source_id'], 'home_issue_items_issue_section_source_unique');
            // The never-again lookup, which asks about a source across every issue there has ever
            // been and so cannot use either unique above (both lead with the issue).
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('home_issue_items');
    }
};
