<?php

namespace App\Features\CommunityTopic;

/**
 * Who may post topics to a community. Successor of OpenPNE 3's opCommunityTopicPlugin
 * community_config `topic_authority` ('public' | 'admin_only'), flattened onto a typed
 * communities column.
 *
 * Values start at 1 (the JoinPolicy convention): OpenPNE 3 stored this as a string, so there is
 * no numeric to preserve, and a 0 case invites PHP falsy-comparison bugs.
 *
 * Note this gates posting topics only. Commenting on a topic is open to any member regardless of
 * this setting (OpenPNE 3 isCreatableCommunityTopicComment = membership), enforced in
 * CommunityTopicAccess.
 */
enum TopicPostAuthority: int
{
    /** Any community member may post (OpenPNE 3 'public'). */
    case Members = 1;

    /** Only community admins may post (OpenPNE 3 'admin_only'). */
    case AdminsOnly = 2;

    /** String slug for serialization. Never pass the raw int to the frontend. */
    public function slug(): string
    {
        return match ($this) {
            self::Members => 'members',
            self::AdminsOnly => 'admins_only',
        };
    }

    /** Human-readable label key, translated via __() on either surface. */
    public function label(): string
    {
        return match ($this) {
            self::Members => 'Members can post',
            self::AdminsOnly => 'Admins only',
        };
    }
}
