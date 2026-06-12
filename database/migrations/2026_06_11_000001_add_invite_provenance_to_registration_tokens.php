<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A pending registration may now be issued by someone other than the registrant: a member
        // invite or an admin invite. `source` records which, and is checked against the registration
        // mode at completion (self only completes in open mode, member_invite in open/invite, etc.),
        // so a stricter mode retroactively blocks links it would no longer issue. `inviter_id` is the
        // member-invite's inviter, used to auto-friend on completion; it is null for self/admin and is
        // nulled if the inviter is deleted, but `source` stays member_invite so only the friending is
        // dropped, not the link.
        Schema::table('registration_tokens', function (Blueprint $table) {
            $table->string('source')->default('self')->after('token');
            $table->foreignId('inviter_id')->nullable()->after('source')
                ->constrained('members')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('registration_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inviter_id');
            $table->dropColumn('source');
        });
    }
};
