<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->string('kind');
            $table->string('channel');
            $table->boolean('is_enabled');
            $table->timestamps();

            // member_id-prefixed so it also serves the FK and the member-anchored reads.
            $table->unique(['member_id', 'kind', 'channel']);
            // Named: the conventional name exceeds MySQL's 64-character identifier limit.
            $table->index(['kind', 'channel', 'is_enabled', 'member_id'], 'member_notification_settings_fanout_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_notification_settings');
    }
};
