<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Stored bytes of uploaded files (the OpenPNE 3 `file_bin` table, kept as-is).
 * This is the physical backend of the default DB-BLOB file storage
 * (DbBlobFileStorage), keyed by `file_id`.
 *
 * The schema here is FROZEN to OpenPNE 3's real DDL — file_id (signed INT) PK,
 * bin (LONGBLOB, nullable), created_at / updated_at (DATETIME NOT NULL) — because
 * the upgrade tool migrates this table by a metadata-only ALTER (rewiring the
 * file_id FK from the old `file` table onto `files`), not by copying its BLOBs.
 * That saves order-of-magnitude I/O at switchover (a single site's bytes can be
 * gigabytes). Adding or dropping any column here would turn that rewire into a
 * full table rebuild and defeat the optimisation, so the column set must not
 * change. The fresh-install schema must equal the upgrade target (one schema for
 * both paths), which is why this matches the real OpenPNE 3 DDL column-for-column.
 *
 * All columns are charset-neutral (INT / LONGBLOB / DATETIME, no character data),
 * so the table DEFAULT CHARSET (utf8mb4 here vs OpenPNE 3's utf8mb3) does not
 * affect the metadata-only property — only character columns would force a rewrite.
 *
 * `bin` is declared via the column builder as BLOB (Laravel's binary() cap on
 * MySQL) and widened to LONGBLOB on MySQL below; SQLite BLOB has no size class.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_bin', function (Blueprint $table) {
            // Signed INT PK matching files.id (file header: keeps the upgrade FK
            // rewire metadata-only). MySQL makes a PK column implicitly NOT NULL.
            $table->integer('file_id')->primary();
            // Nullable to match OpenPNE 3 (bin has no NOT NULL there); the uploader
            // always writes bytes, so it is never null in practice.
            $table->binary('bin')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->foreign('file_id')->references('id')->on('files')->cascadeOnDelete();
        });

        // Laravel's binary() emits MySQL BLOB (64 KiB cap), too small for images.
        // Widen to LONGBLOB on a freshly created empty table (instant, metadata
        // only). SQLite BLOB is unbounded, so it needs no change.
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE file_bin MODIFY bin LONGBLOB NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('file_bin');
    }
};
