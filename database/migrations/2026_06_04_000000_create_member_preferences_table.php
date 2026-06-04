<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-member scalar preferences, keyed by App\Support\PreferenceKey.
 *
 * Replaces the OpenPNE 3 `member_config` KV grab-bag for feature-scoped preferences only:
 * identity-bearing fields graduated to typed `members` columns (email/password/locale/…).
 * One row per (member, key); `value` is the codec output (a Visibility int as string today).
 * An absent row means "follow the key default" — the default is never stored, so changing a
 * key default later applies to everyone who has not explicitly overridden it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->string('key');
            $table->string('value');
            $table->timestamps();

            // One row per preference per member. The unique index is member_id-prefixed, so it
            // also serves the member-anchored reads and the delete cascade (SQLite needs it).
            $table->unique(['member_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_preferences');
    }
};
