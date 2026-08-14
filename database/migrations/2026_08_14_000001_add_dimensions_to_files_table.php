<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The pixel size a stored image renders at — EXIF Orientation applied, since delivery auto-orients —
 * so a surface can reserve the box before the bytes arrive. Nullable: a non-image has no size, and
 * rows written before this column (or whose bytes do not decode) stay NULL until
 * `openpne:backfill-image-dimensions` fills them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table): void {
            $table->unsignedInteger('width')->nullable()->after('byte_size');
            $table->unsignedInteger('height')->nullable()->after('width');
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table): void {
            $table->dropColumn(['width', 'height']);
        });
    }
};
