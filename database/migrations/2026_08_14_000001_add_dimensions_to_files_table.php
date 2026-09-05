<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
