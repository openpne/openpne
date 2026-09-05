<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * `source_id` carries no foreign key and these rows are never swept when the source is deleted
 * (docs/internals/home-issues.md, "An issue is a ledger, never a copy").
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

            // Leads with home_issue_id, so both engines take it as the prefix that backs the FK, and
            // a later drop has to drop the foreign key first (errno 1553).
            $table->unique(['home_issue_id', 'section', 'rank']);
            // Named because the generated name runs past MySQL's 64-character identifier limit
            // (errno 1059), which SQLite does not enforce.
            $table->unique(['home_issue_id', 'section', 'source_type', 'source_id'], 'home_issue_items_issue_section_source_unique');
            // The never-again lookup asks about a source across every issue, so neither unique above
            // reaches it.
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('home_issue_items');
    }
};
