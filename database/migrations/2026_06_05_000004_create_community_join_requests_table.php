<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Pending join requests for Approval-policy communities (the OpenPNE 3 `community_member`
 * is_pre=1 rows). Kept separate from community_members so confirmed-member reads cannot
 * accidentally include applicants — the same split as friend_requests vs friendships.
 *
 * Approval moves a row here into community_members (delete + insert in one transaction),
 * mirroring AcceptFriendRequest. Shape follows friend_requests: composite primary, created_at
 * only, no surrogate id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_join_requests', function (Blueprint $table) {
            $table->foreignId('community_id')->constrained('communities')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['community_id', 'member_id']);
            // "What has this member applied to" lookups.
            $table->index('member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_join_requests');
    }
};
