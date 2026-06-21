<?php

namespace App\Features\Message;

use App\Models\Member;
use App\Models\Message;

/**
 * A resolved message show page: the message plus its box context. `counterparties` is the To set
 * (when the viewer is the sender) or the single From member (otherwise), matching OpenPNE 3's
 * fromOrToMembers. `previousId` / `nextId` are the adjacent messages within the same box (older /
 * newer by id, as OpenPNE 3), null at the ends.
 */
final readonly class MessageView
{
    /**
     * @param  list<Member>  $counterparties
     */
    public function __construct(
        public Message $message,
        public MessageBox $box,
        public bool $viewerIsSender,
        public array $counterparties,
        public ?int $previousId,
        public ?int $nextId,
    ) {}
}
