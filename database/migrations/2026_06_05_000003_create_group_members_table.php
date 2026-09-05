<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Pending join requests live in group_join_requests, so a read of this table is a confirmed
 * member with no extra filter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            // Frozen literal (not GroupRole::Member->value) so a later enum change cannot drift this
            // default.
            $table->unsignedTinyInteger('role')->default(1); // GroupRole::Member
            $table->timestamps();

            // OpenPNE 3 enforced one membership per (group, member) in app code; here it is a DB
            // constraint.
            $table->unique(['group_id', 'member_id']);
            $table->index(['group_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_members');
    }
};
