<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $mysql = DB::connection()->getDriverName() === 'mysql';

        Schema::create('timeline_post_tags', function (Blueprint $table) use ($mysql) {
            $table->id();
            $table->foreignId('timeline_post_id')->constrained('timeline_posts')->cascadeOnDelete();
            // The parser caps a tag at 30 code points; the headroom keeps a later cap change out of
            // a migration.
            $tag = $table->string('tag', 64);
            // utf8mb4_bin pins MySQL to the parser's NFKC + lowercase equivalence, which
            // utf8mb4_unicode_ci would widen by folding accents and kana as SQLite's binary TEXT
            // never does.
            if ($mysql) {
                $tag->collation('utf8mb4_bin');
            }
            $table->unsignedSmallInteger('offset');
            $table->unsignedSmallInteger('length');

            // Keeps two rows off one start offset, and leads with timeline_post_id so it also backs
            // that FK.
            $table->unique(['timeline_post_id', 'offset']);

            // Leads with `tag` because an index starting at the FK column can be adopted as that
            // constraint's backing index and then refuse to drop (errno 1553).
            $table->index(['tag', 'timeline_post_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timeline_post_tags');
    }
};
