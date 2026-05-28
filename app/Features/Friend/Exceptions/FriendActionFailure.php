<?php

namespace App\Features\Friend\Exceptions;

enum FriendActionFailure: string
{
    case SelfFriendship = 'self_friendship';
    case AlreadyFriends = 'already_friends';
    case DuplicateRequest = 'duplicate_request';
    case Blocked = 'blocked';
    case RequestNotFound = 'request_not_found';
    case NotFriends = 'not_friends';
}
