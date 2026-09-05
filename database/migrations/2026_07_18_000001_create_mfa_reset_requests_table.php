<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mfa_reset_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('token')->unique();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mfa_reset_requests');
    }
};
