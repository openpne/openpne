<?php

namespace App\Features\GroupTopic\Queries;

use App\Features\Block\BlockLookup;
use App\Features\GroupTopic\GroupTopicAccess;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use App\Models\Member;
use App\Notifications\CommentReason;

/**
 * One entry per recipient, Reply winning when both apply. GroupTopicAccess checks membership only,
 * so the either-direction block is applied here; a withdrawn author or co-commenter carries a NULL
 * member_id and drops out naturally.
 */
class TopicCommentNotificationRecipients
{
    /** @return list<array{0: Member, 1: CommentReason}> */
    public function __invoke(GroupTopic $topic, Member $commenter): array
    {
        $coCommenterIds = GroupTopicComment::query()
            ->where('group_topic_id', $topic->getKey())
            ->whereNotNull('member_id')
            ->where('member_id', '!=', $commenter->getKey())
            ->when($topic->member_id !== null, fn ($query) => $query->where('member_id', '!=', $topic->member_id))
            ->distinct()
            ->pluck('member_id');

        $recipients = [];

        $author = $topic->member;
        if ($author !== null && ! $author->is($commenter) && $this->canReceive($author, $topic, $commenter)) {
            $recipients[] = [$author, CommentReason::Reply];
        }

        foreach (Member::query()->findMany($coCommenterIds) as $member) {
            if ($this->canReceive($member, $topic, $commenter)) {
                $recipients[] = [$member, CommentReason::Related];
            }
        }

        return $recipients;
    }

    private function canReceive(Member $recipient, GroupTopic $topic, Member $commenter): bool
    {
        return ! $recipient->is_login_rejected
            && GroupTopicAccess::canViewTopic($topic, $recipient)
            && ! BlockLookup::hasAnyBlockBetween($recipient, $commenter);
    }
}
