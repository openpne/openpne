<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['diaries', 'group_topics', 'group_events', 'timeline_posts'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->foreignId('link_card_id')->nullable()->constrained('link_cards')->nullOnDelete();
                $table->timestamp('link_card_synced_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table): void {
                // The constraint goes before the column: InnoDB adopts the index it created for the
                // foreign key, and refuses to drop it while the constraint still exists (errno 1553).
                $table->dropForeign(['link_card_id']);
                $table->dropColumn(['link_card_id', 'link_card_synced_at']);
            });
        }
    }
};
