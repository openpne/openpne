<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * laravel/passkeys reads and writes this table through its own model, whose column names are fixed:
 * the owner column is `user_id` even though it points at members.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        Schema::create('passkeys', function (Blueprint $table) use ($driver) {
            $table->id();
            $table->foreignId('user_id')->constrained('members')->cascadeOnDelete();
            // InnoDB backs every foreign key with an index and SQLite backs none.
            if ($driver === 'sqlite') {
                $table->index('user_id');
            }
            $table->string('name');
            // base64url is case-sensitive, so MySQL's case-insensitive default collation would let
            // two distinct credential IDs collide on the unique index; 512 × 4 bytes stays under the
            // 3072-byte key limit (docs/internals/security.md, "Member passkeys").
            $credentialId = $table->string('credential_id', 512);
            if ($driver === 'mysql') {
                $credentialId->collation('utf8mb4_bin');
            }
            $credentialId->unique();
            $table->json('credential');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passkeys');
    }
};
