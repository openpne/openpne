<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A message_recipients row now means "delivered to this member": it is created when a message is
 * sent, never while it is a draft. So a draft's pending recipient has nowhere to live in
 * message_recipients; it lives here, on the draft itself, and is cleared when the draft is sent (the
 * receipt then holds the recipient). This makes "a draft is never the recipient's" hold by
 * construction — a recipient-side query starts from message_recipients and so can never reach a draft.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('draft_recipient_id')->nullable()->after('sender_id')->constrained('members')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('draft_recipient_id');
        });
    }
};
