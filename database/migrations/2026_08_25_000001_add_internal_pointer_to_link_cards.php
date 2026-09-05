<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * No foreign key: the target may be any of several tables, and a row naming a record that is gone
 * renders as no card.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('link_cards', function (Blueprint $table): void {
            $table->string('internal_context', 32)->nullable();
            $table->unsignedBigInteger('internal_record_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('link_cards', function (Blueprint $table): void {
            $table->dropColumn(['internal_context', 'internal_record_id']);
        });
    }
};
