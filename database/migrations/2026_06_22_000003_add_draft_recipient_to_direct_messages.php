<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A direct_message_recipients row now means "delivered to this member": it is created when a message is
 * sent, never while it is a draft. So a draft's pending recipient lives here, on the draft itself
 * (draft_recipient_id), and is cleared when the draft is sent (the receipt then holds the recipient).
 * This makes "a draft is never the recipient's" hold by construction — a recipient-side query starts
 * from direct_message_recipients and so can never reach a draft.
 *
 * Drafts created under the old model carried a receipt, so this also folds each such draft's recipient
 * onto the column and drops the draft receipt, aligning old data with the new invariant. (Delivery
 * idempotency — no duplicate receipt on a double-submitted send — is enforced in UpdateDraft, which
 * re-checks the draft under a row lock; a unique index on (direct_message_id, recipient_id) was avoided
 * because MySQL adopts it as the direct_message_id foreign key's index, which then blocks rollback.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('direct_messages', function (Blueprint $table) {
            $table->foreignId('draft_recipient_id')->nullable()->after('sender_id')->constrained('members')->nullOnDelete();
        });

        // Fold any pre-existing draft's recipient (held in its receipt under the old model) onto the
        // column, then drop the draft receipts so the new "receipt == delivered" invariant holds. A
        // draft is 1:1, so its single receipt's recipient is the draft recipient.
        DB::table('direct_messages')->where('is_draft', true)->orderBy('id')->each(function (object $draft): void {
            $recipientId = DB::table('direct_message_recipients')->where('direct_message_id', $draft->id)->value('recipient_id');
            if ($recipientId !== null) {
                DB::table('direct_messages')->where('id', $draft->id)->update(['draft_recipient_id' => $recipientId]);
            }
        });
        DB::table('direct_message_recipients')
            ->whereIn('direct_message_id', fn ($q) => $q->select('id')->from('direct_messages')->where('is_draft', true))
            ->delete();
    }

    public function down(): void
    {
        // Restore the receipt each draft carried before up() folded it into the column (old model).
        DB::table('direct_messages')->where('is_draft', true)->whereNotNull('draft_recipient_id')->orderBy('id')->each(function (object $draft): void {
            DB::table('direct_message_recipients')->insert([
                'direct_message_id' => $draft->id,
                'recipient_id' => $draft->draft_recipient_id,
                'created_at' => $draft->created_at,
                'updated_at' => $draft->updated_at,
            ]);
        });

        Schema::table('direct_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('draft_recipient_id');
        });
    }
};
