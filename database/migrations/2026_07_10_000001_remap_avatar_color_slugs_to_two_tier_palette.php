<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The palette moved from twelve 500-level hues to nine families in a light and a deep tier;
     * slugs that no longer exist map to the deep tier of their nearest surviving family (an
     * unmapped slug would make the AvatarColor enum cast throw on read).
     */
    private const REMAP = [
        'yellow' => 'amber',
        'lime' => 'green',
        'emerald' => 'green',
        'cyan' => 'teal',
        'indigo' => 'violet',
        'purple' => 'violet',
    ];

    public function up(): void
    {
        foreach (self::REMAP as $old => $new) {
            DB::table('members')->where('avatar_color', $old)->update(['avatar_color' => $new]);
        }
    }

    public function down(): void
    {
        // Irreversible collapse (two old slugs share a target); the mapped values stay valid.
    }
};
