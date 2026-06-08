<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Event participants (OpenPNE 3 `community_event_member`): the RSVP pivot. A row's presence is the
 * whole signal — OpenPNE 3 has no status column, just joined-or-not. Both FKs cascade: a row is
 * meaningless once its event or member is gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_event_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('community_event_id')->constrained('community_events')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->timestamps();

            // Membership lookup (isParticipant) and the participant-count cap check both key on
            // (event, member); the participant roster pages by event.
            $table->index(['community_event_id', 'member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_event_members');
    }
};
