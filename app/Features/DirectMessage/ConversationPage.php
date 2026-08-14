<?php

namespace App\Features\DirectMessage;

use App\Models\DirectMessage;
use Illuminate\Support\Collection;

/**
 * One slice of a conversation, always oldest-first — the order it is read in, whichever direction it
 * was fetched from. Each message carries its own cursor once serialized, so the page needs no
 * boundary of its own: "load older" asks from the first, the poll watches from the last.
 */
final readonly class ConversationPage
{
    /**
     * @param  Collection<int, DirectMessage>  $messages  ascending by (created_at, id)
     * @param  bool  $hasNewer  whether rows follow this page that the asker does not already hold. A
     *                          read that walks forward and hits its cap says true; a read bounded by
     *                          a position the client gave (or by the newest row) says false, because
     *                          everything past that boundary is already on the client's screen.
     */
    public function __construct(
        public Collection $messages,
        public bool $hasOlder,
        public bool $hasNewer = false,
    ) {}
}
