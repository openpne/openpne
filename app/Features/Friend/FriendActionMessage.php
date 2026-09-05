<?php

declare(strict_types=1);

namespace App\Features\Friend;

use App\Features\Friend\Exceptions\FriendActionFailure;

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
            // OpenPNE 3's unlink notice, verbatim.
            FriendActionFailure::NotFriends => __('This member is not your %friend%.'),
        };
    }
}
