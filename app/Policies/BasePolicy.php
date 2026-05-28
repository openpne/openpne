<?php

namespace App\Policies;

use App\Models\Member;

/**
 * Base class for feature Policies. Centralises authorization helpers that
 * cross feature boundaries — most notably block override — so each feature
 * Policy does not re-implement the rule and risk drift.
 *
 * Block judgment helpers follow the naming convention defined in
 * worklog/current/feature-module-contract.md §認可と可視性:
 * direction and use case must be explicit in the method name. `isBlockedFor`
 * and similar ambiguous names are not used.
 */
abstract class BasePolicy
{
    /**
     * True when $owner has blocked $viewer. Block is a one-way (owner → viewer)
     * visibility override: it hides the owner's content from the blocked
     * viewer. It does not symmetrically hide the viewer's content from the
     * owner — that direction is the viewer's own block, evaluated separately.
     *
     * Used for single-row visibility checks (profile pages, individual diary
     * entries, etc.). For list queries that must filter many owners against
     * one viewer, use the Query scope variant — see below.
     *
     * This method is the temporary single source of truth until the Block
     * feature lands. At that point it will delegate to the Block feature's
     * Support surface; the public method name stays the same so subclasses
     * do not need to be touched.
     */
    protected function ownerBlocksViewer(Member $owner, Member $viewer): bool
    {
        return $owner->blocksMade()->whereKey($viewer->getKey())->exists();
    }

    // Two additional block helpers land with the Block feature primitive on
    // its public Support surface; the names are reserved here so callers
    // settle on the same vocabulary from the start. Concrete signatures are
    // fixed when the Block feature is implemented.
    //
    //   hasAnyBlockBetween(Member $a, Member $b): bool
    //     — symmetric check for interaction gates (friend request guard,
    //       direct message guard). Until the Block feature lands, Friend
    //       Action queries member_blocks directly; that direct query is
    //       replaced with a call to this helper in the same PR that
    //       introduces it.
    //
    //   excludeOwnersBlockingViewer(query, viewer, ownerColumn): Builder
    //     — query-scope variant for list-style filters (Diary list, Friend
    //       list, ...) so N+1 is avoided. Argument order and types are
    //       finalised when the Block feature is implemented.
    //
    // See worklog/current/feature-module-contract.md §認可と可視性 for the
    // naming convention (direction + use case, no ambiguous `isBlockedFor`).
}
