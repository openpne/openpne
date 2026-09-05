<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table): void {
            $table->unsignedBigInteger('talk_reaction_seq')->default(0);
        });

        Schema::table('group_messages', function (Blueprint $table): void {
            // Nullable, not 0: only a version the counter actually issued can be compared.
            $table->unsignedBigInteger('reactions_version')->nullable();
            $table->index(['group_id', 'reactions_version']);
        });
    }

    public function down(): void
    {
        Schema::table('group_messages', function (Blueprint $table): void {
            $table->dropIndex(['group_id', 'reactions_version']);
        });

        Schema::table('group_messages', function (Blueprint $table): void {
            $table->dropColumn('reactions_version');
        });

        Schema::table('groups', function (Blueprint $table): void {
            $table->dropColumn('talk_reaction_seq');
        });
    }
};
