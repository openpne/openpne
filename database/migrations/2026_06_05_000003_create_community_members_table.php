<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Confirmed community memberships (successor of the OpenPNE 3 `community_member` table's
 * is_pre=0 rows). Pending join requests live in their own community_join_requests table, so a
 * read of this table is a confirmed member with no extra filter — mirroring the
 * friendships / friend_requests split and keeping the unsafe (pending) set out of reach.
 *
 * OpenPNE 3's separate community_member_position rows are flattened onto the `role` column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('community_id')->constrained('communities')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            // Member=1 < SubAdmin=2 < Admin=3 (ascending privilege). Frozen literal (not
            // CommunityRole::Member->value) so a later enum change cannot drift this default.
            $table->unsignedTinyInteger('role')->default(1); // CommunityRole::Member
            $table->timestamps();

            // One membership per (community, member): OpenPNE 3 enforced this in app code; here it
            // is a DB constraint and the join idempotency guard.
            $table->unique(['community_id', 'member_id']);
            // Member-list ordering (admins first) and membership lookups.
            $table->index(['community_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_members');
    }
};
