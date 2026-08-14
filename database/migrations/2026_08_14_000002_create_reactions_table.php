<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * One member's one emoji on one piece of content. Polymorphic from the start: group talk is the
 * first surface to carry reactions, and the ones that follow (timeline, direct messages, entries,
 * board posts) are the same row with a different `reactable_type`.
 *
 * The unique key includes `emoji`, so one member may hold several reactions on one message.
 * Narrowing that later is lossy, which is why the wide key is a decision rather than an accident.
 *
 * `reactable_id` carries no foreign key — a polymorphic column cannot — so every deletion path that
 * does not go through the model has to sweep these rows itself
 * (App\Features\GroupTalk\Actions\DeleteGroupMessage, App\Features\Group\Actions\DeleteGroup).
 */
return new class extends Migration
{
    public function up(): void
    {
        $mysql = DB::connection()->getDriverName() === 'mysql';

        Schema::create('reactions', function (Blueprint $table) use ($mysql) {
            $table->id();
            $table->string('reactable_type', 40);
            $table->unsignedBigInteger('reactable_id');
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            // Room for a multi-code-point emoji (a ZWJ sequence, a flag) well past the vocabulary
            // App\Features\Reactions\ReactionVocabulary ships, so widening the set stays a change to
            // that class. The default utf8mb4_unicode_ci equates a bare code point with its
            // VS16-qualified form (U+2764 = U+2764 U+FE0F), which SQLite's binary TEXT does not — the
            // engines would disagree on what the unique key counts as one reaction.
            $emoji = $table->string('emoji', 32);
            if ($mysql) {
                $emoji->collation('utf8mb4_bin');
            }
            $table->timestamps();

            // One row per (content, member, emoji), and its prefix is the read: every reaction on
            // one message. 304 bytes at utf8mb4, well inside InnoDB's key limit.
            $table->unique(['reactable_type', 'reactable_id', 'member_id', 'emoji']);
            // Declared rather than left to InnoDB, which would invent an unnamed one for the FK and
            // leave SQLite with none. It is the constraint's backing index, so any later migration
            // dropping it must drop the foreign key first (MySQL errno 1553).
            $table->index('member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reactions');
    }
};
