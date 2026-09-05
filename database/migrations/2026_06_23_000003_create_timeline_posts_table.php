<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timeline_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('in_reply_to_id')->nullable();
            // OpenPNE 3 activity_data.body is string(140), so the cap here is 140.
            $table->string('body', 140);
            $table->unsignedTinyInteger('visibility')->default(1); // Visibility::Members
            $table->timestamps();

            $table->foreign('in_reply_to_id')->references('id')->on('timeline_posts')->cascadeOnDelete();
            $table->index(['member_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timeline_posts');
    }
};
