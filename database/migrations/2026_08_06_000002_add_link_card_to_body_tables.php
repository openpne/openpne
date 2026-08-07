<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Attaches a link card to the bodies that can carry one.
 *
 * A column per body table rather than a polymorphic join: one record has at most one card (the
 * first URL in it, as Twitter, Slack and Mastodon all do), so the relation is one-to-one from this
 * side and a column keeps referential integrity, eager loading and nullOnDelete simple.
 *
 * `link_card_synced_at` is not decoration. It is what distinguishes "this body has no card because
 * it has no URL" from "this body has never been looked at" — a record posted before the feature was
 * enabled, or while it was off. Without it the read path could not tell whether there is work to do
 * without re-parsing every body on every view.
 *
 * Comments and messages are deliberately absent: comments are numerous and gain little, and
 * fetching a URL from a private message would tell its destination that the link was shared.
 */
return new class extends Migration
{
    /** Body tables whose records may carry a card. */
    private const TABLES = ['diaries', 'community_topics', 'community_events', 'timeline_posts'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->foreignId('link_card_id')->nullable()->constrained('link_cards')->nullOnDelete();
                $table->timestamp('link_card_synced_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table): void {
                // The constraint goes before the column: InnoDB adopts the index it created for the
                // foreign key, and refuses to drop it while the constraint still exists (errno 1553).
                $table->dropForeign(['link_card_id']);
                $table->dropColumn(['link_card_id', 'link_card_synced_at']);
            });
        }
    }
};
