<?php

namespace App\Features\Auth\Actions;

/**
 * The self-service entry shows the same neutral screen for Issued and AlreadyMember, so the two are
 * indistinguishable to a guest; the invite entries, whose caller is authenticated, tell them apart.
 */
enum IssueResult
{
    case Issued;
    case AlreadyMember;

    /** No seat left (App\Features\Auth\MemberCapacity): nothing issued, nothing mailed. */
    case CapacityFull;
}
