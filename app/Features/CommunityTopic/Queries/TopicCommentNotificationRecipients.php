<?php

namespace App\Features\CommunityTopic\Queries;

use App\Features\Block\BlockLookup;
use App\Features\CommunityTopic\CommunityTopicAccess;
use App\Models\CommunityTopic;
use App\Models\CommunityTopicComment;
use App\Models\Member;
use App\Notifications\CommentReason;

/**
 * Who a new topic comment notifies: the topic author (Reply) and the distinct co-commenters
 * (Related), never the commenter. One entry per recipient, Reply winning when both apply.
 *
 * Every recipient must currently be able to receive it: not banned (is_login_rejected — banned
 * members cannot receive, as in messaging), able to view the topic right now (board read
 * access), and — the access class checks membership only, so it is applied here — no block in
 * either direction against the commenter. A withdrawn author/co-commenter's rows carry a NULL
 * member_id and drop out naturally.
 */
class TopicCommentNotificationRecipients
{
    /** @return list<array{0: Member, 1: CommentReason}> */
    public function __invoke(CommunityTopic $topic, Member $commenter): array
    {
        $coCommenterIds = CommunityTopicComment::query()
            ->where('community_topic_id', $topic->getKey())
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

    private function canReceive(Member $recipient, CommunityTopic $topic, Member $commenter): bool
    {
        return ! $recipient->is_login_rejected
            && CommunityTopicAccess::canViewTopic($topic, $recipient)
            && ! BlockLookup::hasAnyBlockBetween($recipient, $commenter);
    }
}
