<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->string('title');
            $table->text('body');
            // Restriction level: Open=0 < Members=1 < Friends=2 < Private=3 (monotonic).
            // 1/2/3 match OpenPNE 3 public_flag values for upgrade fidelity.
            $table->unsignedTinyInteger('visibility')->default(1); // Visibility::Members
            $table->timestamps();

            // Drives the personal archive query: WHERE member_id=? ORDER BY created_at DESC.
            // Feed indexes (visibility + created_at) added when feed routes land.
            $table->index(['member_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diaries');
    }
};
