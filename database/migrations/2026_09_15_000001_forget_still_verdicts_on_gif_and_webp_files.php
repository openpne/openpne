<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * A false recorded for a GIF or WebP may be GD's, which kept one frame and could not tell, so it goes back to
 * unknown for a frame-keeping processor's warm to record (docs/internals/images.md, "files.width / files.height").
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('files')->whereIn('type', ['image/gif', 'image/webp'])->where('animated', false)->update(['animated' => null]);
    }

    public function down(): void
    {
        // Nothing to restore: `openpne:image-cache warm` records the facts again.
    }
};
