<?php

namespace App\Features\Auth\Actions;

/**
 * The outcome of issuing a registration token. The self-service entry ignores this (it always shows
 * the same neutral screen, so a token issue and an already-registered no-op are indistinguishable);
 * the invite entries, whose caller is authenticated, use it to tell the inviter the address was
 * already taken.
 */
enum IssueResult
{
    case Issued;
    case AlreadyMember;
}
