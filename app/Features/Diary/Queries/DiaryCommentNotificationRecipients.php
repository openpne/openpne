<?php

namespace App\Features\Diary\Queries;

use App\Features\Block\BlockLookup;
use App\Features\Diary\DiaryAccess;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\Member;
use App\Notifications\CommentReason;

/**
 * Who a new diary comment notifies: the diary owner (Reply) and the distinct co-commenters
 * (Related), never the commenter. One entry per recipient, Reply winning when both apply.
 *
 * Every recipient must currently be able to receive it: not banned (is_login_rejected — banned
 * members cannot receive, as in messaging), able to view the diary right now, and with no block
 * in either direction against the commenter. A withdrawn co-commenter's rows carry a NULL
 * member_id and drop out naturally.
 */
class DiaryCommentNotificationRecipients
{
    /** @return list<array{0: Member, 1: CommentReason}> */
    public function __invoke(Diary $diary, Member $commenter): array
    {
        $coCommenterIds = DiaryComment::query()
            ->where('diary_id', $diary->getKey())
            ->whereNotNull('member_id')
            ->where('member_id', '!=', $commenter->getKey())
            ->when($diary->member_id !== null, fn ($query) => $query->where('member_id', '!=', $diary->member_id))
            ->distinct()
            ->pluck('member_id');

        $recipients = [];

        $owner = $diary->member;
        if ($owner !== null && ! $owner->is($commenter) && $this->canReceive($owner, $diary, $commenter)) {
            $recipients[] = [$owner, CommentReason::Reply];
        }

        foreach (Member::query()->findMany($coCommenterIds) as $member) {
            if ($this->canReceive($member, $diary, $commenter)) {
                $recipients[] = [$member, CommentReason::Related];
            }
        }

        return $recipients;
    }

    private function canReceive(Member $recipient, Diary $diary, Member $commenter): bool
    {
        return ! $recipient->is_login_rejected
            && DiaryAccess::canView($recipient, $diary)
            && ! BlockLookup::hasAnyBlockBetween($recipient, $commenter);
    }
}
