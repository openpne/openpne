<?php

declare(strict_types=1);

namespace App\Features\Friend;

use App\Features\Friend\Exceptions\FriendActionFailure;

/**
 * What a member is told when a %friend% action refuses. Shared so the pages and the notification
 * center answer the same failure with the same sentence.
 */
class FriendActionMessage
{
    public static function for(FriendActionFailure $reason): string
    {
        return match ($reason) {
            FriendActionFailure::SelfFriendship => __('You cannot send a %friend% request to yourself.'),
            FriendActionFailure::AlreadyFriends => __('You are already %friends%.'),
            FriendActionFailure::DuplicateRequest => __('A pending request already exists.'),
            FriendActionFailure::Blocked => __('This member is unavailable.'),
            FriendActionFailure::RequestNotFound => __('No pending %friend% request found.'),
            // OpenPNE 3's unlink notice, verbatim: this is what a member sees on the manage page.
            FriendActionFailure::NotFriends => __('This member is not your %friend%.'),
        };
    }
}
