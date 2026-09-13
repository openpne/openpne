<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table): void {
            // Nullable: null is "not recorded", as for width/height (docs/internals/images.md, "files.width / files.height").
            $table->boolean('animated')->nullable()->after('height');
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table): void {
            $table->dropColumn('animated');
        });
    }
};
