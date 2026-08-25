<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Where a card of this site's own URL points (App\LinkCard\InternalCardTarget + the record's id).
 *
 * A pointer and nothing else: what such a card shows depends on who is reading it, and this table is
 * shared by every body that mentions the URL, so the content is assembled from the record at render
 * time and the metadata columns stay null. No foreign key — the target may be any of seven tables,
 * and a row naming a record that is gone renders as no card, which is the same answer a record the
 * reader may not see gets.
 *
 * Not indexed: nothing looks a card up by its target. The lookup is always the other way round.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('link_cards', function (Blueprint $table): void {
            $table->string('internal_context', 32)->nullable();
            $table->unsignedBigInteger('internal_record_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('link_cards', function (Blueprint $table): void {
            $table->dropColumn(['internal_context', 'internal_record_id']);
        });
    }
};
