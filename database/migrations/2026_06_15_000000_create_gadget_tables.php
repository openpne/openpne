<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-configurable Classic gadgets (OpenPNE 3 `gadget` + `gadget_config`). The OpenPNE 3 `type`
 * (e.g. `profileSideMenu`) is split into `context` + `zone`; `source_type` keeps the original so a
 * site's custom CSS still matches.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gadgets', function (Blueprint $table) {
            $table->id();
            $table->string('context', 32);
            $table->string('zone', 32);
            $table->string('name', 64);
            $table->string('source_type', 64)->nullable();
            $table->integer('sort_order')->nullable();
            $table->timestamps();

            $table->index(['context', 'zone', 'sort_order']);
        });

        // name/value KV per gadget (no timestamps).
        Schema::create('gadget_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gadget_id')->constrained('gadgets')->cascadeOnDelete();
            $table->string('name', 64);
            $table->text('value')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gadget_configs');
        Schema::dropIfExists('gadgets');
    }
};
