<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Community events (OpenPNE 3 opCommunityEventPlugin `community_event` table): a community's
 * scheduled gatherings. Same shape as community_topics plus the scheduling fields (open_date /
 * area / deadline / capacity) that drive the RSVP model in community_event_members.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('community_id')->constrained('communities')->cascadeOnDelete();
            // Keep the event when its author is deleted (OpenPNE 3 Member onDelete: set null).
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            // OpenPNE 3 name/body/open_date_comment/area are Doctrine `type: string` (no length) =
            // MySQL TEXT, with no validator length limit; TEXT so migrated content is not truncated.
            $table->text('name');
            $table->text('body');
            // "Last edited" timestamp OpenPNE 3 bumps on a name/body edit and on a new comment,
            // feeding its "latest events" sidebar/API widgets. (The board itself orders by
            // updated_at — see index.) Nullable until the first bump.
            $table->dateTime('event_updated_at')->nullable();
            // Event day. OpenPNE 3 stores a date (the form widget is date-only); the time-of-day is
            // the free-text open_date_comment beside it.
            $table->dateTime('open_date');
            // Free-text time note ("14:00-16:00"). NOT NULL in OpenPNE 3 but the form lets it be
            // empty, so it stores '' rather than null.
            $table->text('open_date_comment');
            // Venue / location, free text.
            $table->text('area');
            // RSVP cutoff. Null = no deadline. Joining is allowed through deadline + 1 day.
            $table->dateTime('application_deadline')->nullable();
            // Participant cap. Null = unlimited. Enforced at join time against community_event_members.
            $table->integer('capacity')->nullable();
            $table->timestamps();

            // Board query: WHERE community_id=? ORDER BY updated_at DESC (a new comment touches the
            // parent event's updated_at, so the most recently active event sorts first).
            $table->index(['community_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_events');
    }
};
