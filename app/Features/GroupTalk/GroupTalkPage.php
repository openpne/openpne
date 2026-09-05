<?php

namespace App\Features\GroupTalk;

use App\Models\GroupMessage;
use Illuminate\Support\Collection;

final readonly class GroupTalkPage
{
    /**
     * @param  Collection<int, GroupMessage>  $messages  ascending by (created_at, id)
     * @param  bool  $hasNewer  whether rows follow this page that the asker does not already hold: a
     *                          forward read that hits its cap says true, a read bounded by a
     *                          client-given position or by the newest row says false
     */
    public function __construct(
        public Collection $messages,
        public bool $hasOlder,
        public bool $hasNewer = false,
    ) {}
}
