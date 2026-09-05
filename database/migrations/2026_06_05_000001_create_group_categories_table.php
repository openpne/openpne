<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64);
            // Admin-only categories exist in OpenPNE 3; the flag gates create-form eligibility, not
            // just display.
            $table->boolean('is_allow_member_group')->default(true);
            $table->integer('sort_order')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('group_categories')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_categories');
    }
};
