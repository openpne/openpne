<?php

namespace App\Features\DirectMessage;

use App\Models\DirectMessage;
use App\Models\Member;

/**
 * One row of the conversation list: who it is with, the message it leads with, and how much of it
 * the viewer has not read. A null counterpart is the withdrawn bucket.
 *
 * `latest` is never null: a conversation exists because a message exists in it.
 */
final readonly class ConversationSummary
{
    public function __construct(
        public ?Member $counterpart,
        public DirectMessage $latest,
        public int $unread,
    ) {}
}
