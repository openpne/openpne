<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Features\DirectMessage\ConversationScope;
use App\Models\DirectMessage;
use App\Models\Member;
use App\Support\Feature;

abstract class DirectMessageTool extends McpTool
{
    protected const REFUSED = 'No such conversation, member or message — or it is not yours to read.';

    public function shouldRegister(): bool
    {
        return Feature::DirectMessage->enabled();
    }

    /**
     * The other side of a conversation. Yourself and an id naming nobody answer alike, as the
     * conversation screen 404s both. The withdrawn bucket names no member and is not addressable here.
     */
    protected function counterpart(Member $viewer, int $memberId): ?Member
    {
        $member = Member::query()->find($memberId);

        return $member === null || $viewer->is($member) ? null : $member;
    }

    /**
     * Who an AI account may write to: its owner, and a member who has written to it first. A person
     * chooses whom to write to; an agent holding a token could otherwise open a conversation with
     * every member of the site, and a direct message is the one surface with no room to leave.
     */
    protected static function mayWrite(Member $sender, Member $recipient): bool
    {
        if (! $sender->isAiAccount() || (int) $sender->owner_member_id === (int) $recipient->getKey()) {
            return true;
        }

        return ConversationScope::apply(DirectMessage::query(), $sender, $recipient)
            ->where('sender_id', $recipient->getKey())
            ->exists();
    }
}
