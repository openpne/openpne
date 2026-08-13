<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tables whose body carries a per-record render format (BodyFormat). */
    private const TABLES = ['diaries', 'group_topics', 'community_events'];

    public function up(): void
    {
        // Per-record body render format (BodyFormat). Default plain: authored OpenPNE 4 content is
        // plain; the upgrade tags migrated diary rows op3 explicitly (DiaryUpgrade).
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->string('format', 16)->default('plain');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->dropColumn('format');
            });
        }
    }
};
