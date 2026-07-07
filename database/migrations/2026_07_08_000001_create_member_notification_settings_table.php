<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-member notification opt-in/out, keyed by App\Notifications\Settings\NotificationKind and
 * channel ('web' | 'mail'). One row per (member, kind, channel); an absent row means "the kind's
 * default" (enabled). Channel is a row dimension, not two boolean columns, so the upgrade maps
 * one source row to one row here.
 */
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
            // Broadcast fan-out reads the opted-out member set per (kind, channel) in one query.
            // Named explicitly: the auto-generated name exceeds MySQL's 64-char identifier limit.
            $table->index(['kind', 'channel', 'is_enabled', 'member_id'], 'member_notification_settings_fanout_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_notification_settings');
    }
};
