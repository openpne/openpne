<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Frozen to OpenPNE 3's four columns: the upgrade migrates this table by a metadata-only ALTER,
 * and adding or dropping one would turn that into a full rebuild (docs/internals/upgrade.md,
 * "file_bin").
 */
return new class extends Migration
{
    public function up(): void
    {
        // A same-database upgrade restores the dump's own file_bin before migrate runs, so skipping
        // the create keeps its rows for the runner to re-point onto `files`.
        if (Schema::hasTable('file_bin')) {
            return;
        }

        Schema::create('file_bin', function (Blueprint $table) {
            // Signed INT PK to match files.id; MySQL makes a PK implicitly NOT NULL, as the frozen
            // DDL requires.
            $table->integer('file_id')->primary();
            // Nullable to match OpenPNE 3 (bin has no NOT NULL there); the uploader
            // always writes bytes, so it is never null in practice.
            $table->binary('bin')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->foreign('file_id')->references('id')->on('files')->cascadeOnDelete();
        });

        // Laravel's binary() emits MySQL BLOB (64 KiB cap), too small for images; SQLite BLOB is
        // unbounded and needs no widening.
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE file_bin MODIFY bin LONGBLOB NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('file_bin');
    }
};
