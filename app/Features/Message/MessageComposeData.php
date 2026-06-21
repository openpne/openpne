<?php

namespace App\Features\Message;

/**
 * Validated input for composing a new message (compose or reply). The recipient is fixed here; a
 * draft edit changes only subject/body (the recipient stays the draft's original). parentId/threadId
 * carry OpenPNE 3's reply links (return_message_id / thread_message_id), null for a fresh message.
 */
final readonly class MessageComposeData
{
    public function __construct(
        public int $recipientId,
        public string $subject,
        public string $body,
        public ?int $parentId = null,
        public ?int $threadId = null,
    ) {}
}
