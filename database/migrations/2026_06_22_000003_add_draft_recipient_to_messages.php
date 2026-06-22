<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A message_recipients row now means "delivered to this member": it is created when a message is
 * sent, never while it is a draft. So a draft's pending recipient lives here, on the draft itself
 * (draft_recipient_id), and is cleared when the draft is sent (the receipt then holds the recipient).
 * This makes "a draft is never the recipient's" hold by construction — a recipient-side query starts
 * from message_recipients and so can never reach a draft.
 *
 * Drafts created under the old model carried a receipt, so this also folds each such draft's recipient
 * onto the column and drops the draft receipt, and adds a (message_id, recipient_id) unique index that
 * keeps a delivery idempotent (a double-submit can never insert a second receipt).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('draft_recipient_id')->nullable()->after('sender_id')->constrained('members')->nullOnDelete();
        });

        // Fold any pre-existing draft's recipient (held in its receipt under the old model) onto the
        // column, then drop the draft receipts so the new "receipt == delivered" invariant holds. A
        // draft is 1:1, so its single receipt's recipient is the draft recipient.
        DB::table('messages')->where('is_draft', true)->orderBy('id')->each(function (object $draft): void {
            $recipientId = DB::table('message_recipients')->where('message_id', $draft->id)->value('recipient_id');
            if ($recipientId !== null) {
                DB::table('messages')->where('id', $draft->id)->update(['draft_recipient_id' => $recipientId]);
            }
        });
        DB::table('message_recipients')
            ->whereIn('message_id', fn ($q) => $q->select('id')->from('messages')->where('is_draft', true))
            ->delete();

        Schema::table('message_recipients', function (Blueprint $table) {
            $table->unique(['message_id', 'recipient_id']);
        });
    }

    public function down(): void
    {
        Schema::table('message_recipients', function (Blueprint $table) {
            $table->dropUnique(['message_id', 'recipient_id']);
        });

        // Restore the receipt each draft carried before up() folded it into the column (old model).
        DB::table('messages')->where('is_draft', true)->whereNotNull('draft_recipient_id')->orderBy('id')->each(function (object $draft): void {
            DB::table('message_recipients')->insert([
                'message_id' => $draft->id,
                'recipient_id' => $draft->draft_recipient_id,
                'created_at' => $draft->created_at,
                'updated_at' => $draft->updated_at,
            ]);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('draft_recipient_id');
        });
    }
};
