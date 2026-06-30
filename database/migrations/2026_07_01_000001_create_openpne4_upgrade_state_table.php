<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Checkpoint table for the OpenPNE 3 → 4 upgrade runner (openpne:upgrade-from-3). One row per step,
 * keyed by step_key, recording completed / failed so a re-run skips finished steps and resumes from
 * the first incomplete one. A per-step transaction makes "completed" mean the copy committed.
 *
 * Named openpne4_upgrade_state (never *_migration_*) to stay distinct from Laravel's schema
 * migrations. dateTime, not timestamps(), per the project's DATETIME convention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('openpne4_upgrade_state', function (Blueprint $table) {
            $table->id();
            $table->string('step_key')->unique();
            $table->string('status'); // running | completed | failed
            $table->unsignedBigInteger('rows_affected')->nullable();
            $table->json('metadata')->nullable(); // e.g. the file_id snapshot a later step records
            $table->text('error')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('openpne4_upgrade_state');
    }
};
