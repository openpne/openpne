<?php

namespace App\Features\CommunityEvent\Queries;

use App\Features\Block\BlockLookup;
use App\Features\CommunityEvent\CommunityEventAccess;
use App\Models\CommunityEvent;
use App\Models\CommunityEventComment;
use App\Models\Member;
use App\Notifications\CommentReason;

/**
 * Who a new event comment notifies: the event author (Reply) and the distinct co-commenters
 * (Related), never the commenter. Same conditions as the topic twin: not banned, current board
 * read access, and no block in either direction against the commenter (the access class checks
 * membership only, so the block is applied here).
 */
class EventCommentNotificationRecipients
{
    /** @return list<array{0: Member, 1: CommentReason}> */
    public function __invoke(CommunityEvent $event, Member $commenter): array
    {
        $coCommenterIds = CommunityEventComment::query()
            ->where('community_event_id', $event->getKey())
            ->whereNotNull('member_id')
            ->where('member_id', '!=', $commenter->getKey())
            ->when($event->member_id !== null, fn ($query) => $query->where('member_id', '!=', $event->member_id))
            ->distinct()
            ->pluck('member_id');

        $recipients = [];

        $author = $event->member;
        if ($author !== null && ! $author->is($commenter) && $this->canReceive($author, $event, $commenter)) {
            $recipients[] = [$author, CommentReason::Reply];
        }

        foreach (Member::query()->findMany($coCommenterIds) as $member) {
            if ($this->canReceive($member, $event, $commenter)) {
                $recipients[] = [$member, CommentReason::Related];
            }
        }

        return $recipients;
    }

    private function canReceive(Member $recipient, CommunityEvent $event, Member $commenter): bool
    {
        return ! $recipient->is_login_rejected
            && CommunityEventAccess::canViewEvent($event, $recipient)
            && ! BlockLookup::hasAnyBlockBetween($recipient, $commenter);
    }
}
