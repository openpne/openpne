<?php

namespace App\Features\DirectMessage;

/**
 * `parentId` / `threadId` carry OpenPNE 3's reply links (`return_message_id` / `thread_message_id`).
 * A null `subject` is a message with no subject at all, which the storage keeps distinct from the
 * empty string.
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
