<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('term_overrides', function (Blueprint $table) {
            $table->string('name', 64);
            $table->string('locale', 8);
            $table->string('value', 255);

            $table->primary(['name', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('term_overrides');
    }
};
