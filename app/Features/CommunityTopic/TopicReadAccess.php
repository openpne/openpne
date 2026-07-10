<?php

namespace App\Features\CommunityTopic;

/**
 * Who may read a community's topics.
 *
 * Values start at 1 (the JoinPolicy convention): OpenPNE 3 stored this as a string, so there is
 * no numeric to preserve, and a 0 case invites PHP falsy-comparison bugs.
 */
enum TopicReadAccess: int
{
    /** Any signed-in member may read. */
    case Everyone = 1;

    /** Only community members may read. */
    case MembersOnly = 2;

    /** String slug for serialization. Never pass the raw int to the frontend. */
    public function slug(): string
    {
        return match ($this) {
            self::Everyone => 'everyone',
            self::MembersOnly => 'members_only',
        };
    }

    /** Human-readable label key, translated via __() on either surface. */
    public function label(): string
    {
        return match ($this) {
            self::Everyone => 'Anyone can read',
            self::MembersOnly => 'Members only',
        };
    }
}
