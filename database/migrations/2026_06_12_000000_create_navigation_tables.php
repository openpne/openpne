<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-configurable Classic navigation (OpenPNE 3 `navigation` + its i18n table).
 *
 * One row is a menu item for a navigation `type` (the PC contexts secure_global /
 * insecure_global / default / friend / community). `uri` is the normalized link target the
 * renderer resolves — a single-slash internal path (with an optional `:id` placeholder for the
 * friend/community contexts) or an http(s) URL. `source_uri` keeps the original OpenPNE 3 value
 * so the rendered `<li>` id stays byte-for-byte compatible with custom CSS (App\Models\Navigation
 * derives the id from it); it is null for admin-created rows. Captions live in
 * `navigation_translations` keyed by (id, lang), mirroring OpenPNE 3's Doctrine I18n behaviour.
 *
 * uri/source_uri are text because OpenPNE 3 stored uri as TEXT (external URLs can be long).
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
