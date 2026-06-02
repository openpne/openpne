<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A member's value for one profile field.
 *
 * Redesigned from OpenPNE 3's `member_profile`: the nested-set columns
 * (lft/rgt/level/tree_key) are dropped. One row per value — a single-value field is one
 * row; a multi-select (checkbox) is one row per chosen option (each carrying
 * profile_option_id); a custom date is one row holding the composed Y-m-d. Preset
 * select/radio store the choice key in `value` (profile_option_id null); custom
 * select/radio/checkbox use profile_option_id.
 *
 * `visibility` is the per-value visibility (App\Support\Visibility scale, 0=Open..3=Private);
 * null falls back to the field's profiles.default_visibility (resolved in the read layer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignId('profile_option_id')->nullable()->constrained('profile_options')->cascadeOnDelete();
            $table->text('value')->nullable();
            $table->dateTime('value_datetime')->nullable();
            $table->unsignedTinyInteger('visibility')->nullable();
            $table->timestamps();

            // SQLite does not auto-index FK columns; MySQL/InnoDB does.
            $table->index('member_id');
            $table->index('profile_option_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_profiles');
    }
};
