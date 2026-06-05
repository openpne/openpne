<?php

use App\Features\Community\CommunityRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Community memberships (successor of the OpenPNE 3 `community_member` table).
 *
 * OpenPNE 3's separate community_member_position rows are flattened onto the `role` column.
 * `is_pre` (pending approval) stays a distinct boolean: a pending member has is_pre=1, role=Member.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('community_id')->constrained('communities')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            // Member=1 < SubAdmin=2 < Admin=3 (ascending privilege).
            $table->unsignedTinyInteger('role')->default(CommunityRole::Member->value);
            // A pending member awaiting admin approval (Approval policy).
            $table->boolean('is_pre')->default(false);
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
