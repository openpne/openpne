<?php

namespace App\Features\DirectMessage;

/**
 * Validated input for composing a new message (compose or reply). The recipient is fixed here; a
 * draft edit changes only subject/body (the recipient stays the draft's original). parentId/threadId
 * carry OpenPNE 3's reply links (return_message_id / thread_message_id), null for a fresh message.
 *
 * A null subject is a message with no subject at all, which is what a chat send writes; the mailbox
 * forms require one, and the storage keeps the distinction from the empty string.
 */
final readonly class DirectMessageComposeData
{
    public function __construct(
        public int $recipientId,
        public ?string $subject,
        public string $body,
        public ?int $parentId = null,
        public ?int $threadId = null,
    ) {}
}
