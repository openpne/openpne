<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-configurable Classic gadgets (OpenPNE 3 `gadget` + `gadget_config`).
 *
 * A gadget is a widget block placed in a page zone. OpenPNE 3 stored the placement as a single
 * `type` (e.g. `profileSideMenu`); OpenPNE 4 splits it into `context` (home/profile/login/sidebanner)
 * + `zone` (top/sideMenu/contents/bottom) so the renderer and admin can group by either. The
 * original `type` is kept in `source_type` for DOM-id / custom-CSS compatibility after the split.
 * `name` is the gadget kind (App\Gadgets\GadgetKindRegistry); a row whose kind is not registered is
 * hidden at render. Per-gadget settings live in `gadget_configs` as a name/value KV, the OpenPNE 3
 * shape.
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

        // name/value KV per gadget, the OpenPNE 3 gadget_config shape (no timestamps needed).
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
