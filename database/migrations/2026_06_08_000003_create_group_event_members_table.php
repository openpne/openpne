<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * OpenPNE 3 has no RSVP status column, so a row's presence is the whole signal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_event_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_event_id')->constrained('group_events')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['group_event_id', 'member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_event_members');
    }
};
