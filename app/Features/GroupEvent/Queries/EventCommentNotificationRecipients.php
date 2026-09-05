<?php

namespace App\Features\GroupEvent\Queries;

use App\Features\Block\BlockLookup;
use App\Features\GroupEvent\GroupEventAccess;
use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use App\Models\Member;
use App\Notifications\CommentReason;

/**
 * One entry per recipient, Reply winning when both apply. GroupEventAccess checks membership only,
 * so the either-direction block is applied here.
 */
class EventCommentNotificationRecipients
{
    /** @return list<array{0: Member, 1: CommentReason}> */
    public function __invoke(GroupEvent $event, Member $commenter): array
    {
        $coCommenterIds = GroupEventComment::query()
            ->where('group_event_id', $event->getKey())
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

    private function canReceive(Member $recipient, GroupEvent $event, Member $commenter): bool
    {
        return ! $recipient->is_login_rejected
            && GroupEventAccess::canViewEvent($event, $recipient)
            && ! BlockLookup::hasAnyBlockBetween($recipient, $commenter);
    }
}
