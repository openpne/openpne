<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `uri` / `source_uri` are TEXT because OpenPNE 3 stored `uri` as TEXT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('navigations', function (Blueprint $table) {
            $table->id();
            $table->string('type', 64);
            $table->text('uri');
            $table->text('source_uri')->nullable();
            $table->integer('sort_order')->nullable();
            $table->timestamps();

            $table->index(['type', 'sort_order']);
        });

        // (id, lang) composite PK, no own id/timestamps — the OpenPNE 3 I18n table shape.
        Schema::create('navigation_translations', function (Blueprint $table) {
            $table->unsignedBigInteger('id');
            $table->text('caption');
            $table->string('lang', 5);

            $table->primary(['id', 'lang']);
            $table->foreign('id')->references('id')->on('navigations')->cascadeOnDelete()->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('navigation_translations');
        Schema::dropIfExists('navigations');
    }
};
