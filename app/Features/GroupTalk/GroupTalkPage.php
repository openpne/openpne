<?php

namespace App\Features\GroupTalk;

use App\Models\GroupMessage;
use Illuminate\Support\Collection;

/**
 * One slice of a group's talk, always oldest-first — the order it is read in, whichever direction it
 * was fetched from. Each message carries its own cursor once serialized, so the page needs no
 * boundary of its own: "load older" asks from the first, the poll watches from the last.
 */
final readonly class GroupTalkPage
{
    /** @param  Collection<int, GroupMessage>  $messages  ascending by (created_at, id) */
    public function __construct(
        public Collection $messages,
        public bool $hasOlder,
    ) {}
}
