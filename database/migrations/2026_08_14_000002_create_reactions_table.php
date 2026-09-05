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

        Schema::create('reactions', function (Blueprint $table) use ($mysql) {
            $table->id();
            $table->string('reactable_type', 40);
            $table->unsignedBigInteger('reactable_id');
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            // utf8mb4_bin, because MySQL's default equates a code point with its VS16-qualified form
            // and SQLite's binary TEXT does not (docs/internals/group-talk.md, "Reactions").
            $emoji = $table->string('emoji', 32);
            if ($mysql) {
                $emoji->collation('utf8mb4_bin');
            }
            $table->timestamps();

            // Its prefix is the read of every reaction on one message, and 304 bytes at utf8mb4 stays
            // inside InnoDB's key limit.
            $table->unique(['reactable_type', 'reactable_id', 'member_id', 'emoji']);
            // Declared rather than left to InnoDB, which leaves SQLite with none, and it backs the FK,
            // so a later drop has to drop the foreign key first (errno 1553).
            $table->index('member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reactions');
    }
};
