<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Community categories (successor of the OpenPNE 3 `community_category` table).
 *
 * OpenPNE 3 stored a NestedSet tree (lft/rgt/level/tree_key), but the pc_frontend only ever
 * used the categories as a flat select on the create form and a search filter. OpenPNE 4 keeps
 * a flat admin-managed master and drops the tree, retaining only an optional `parent_id` for a
 * possible shallow hierarchy later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64);
            // Whether ordinary members may create a community in this category. Admin-only
            // categories exist in OpenPNE 3; this gates create-form eligibility, not just display.
            $table->boolean('is_allow_member_community')->default(true);
            $table->integer('sort_order')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('community_categories')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_categories');
    }
};
