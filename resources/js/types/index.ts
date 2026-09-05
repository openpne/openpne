import type { LookId } from '@/lib/member-chrome';

export interface AuthUser {
    id: number;
    name: string;
    email: string;
    imageUrl: string | null;
    avatarColor: string | null;
    /** Always false — an AI account has no credentials — but the shape is a member reference. */
    isAi: boolean;
}

export interface SnsLogo {
    color: string;
    url: string | null;
}

export interface UnreadCounts {
    friendRequests: number;
    /** Conversations with something new, not messages. */
    unreadMessages: number;
    notifications: number;
    /** Groups whose talk has something new — rooms, not messages, and never a muted one. */
    groupTalks: number;
}

export interface NineTableItem {
    id: number;
    name: string;
    imageUrl: string | null;
    /** Member rows carry the chosen badge color (hex); group rows are always null.
     *  Required (not optional) so a serializer that forgets it fails type-check. */
    avatarColor: string | null;
    /** Always false for a group row. */
    isAi: boolean;
    href: string;
}

export interface RightRail {
    /** The viewer's friends, or an SNS-wide sample while the `friend` unit is switched off. */
    people: {
        kind: 'friends' | 'members';
        items: NineTableItem[];
    };
}

/** `id` is the group's, not a room's. */
export interface TalkNavRoom {
    id: number;
    name: string;
    imageUrl: string | null;
    unread: number;
    muted: boolean;
}

/** A slice of the joined room list in the same order, with no previews. */
export interface TalkNavRooms {
    rooms: TalkNavRoom[];
    hasMore: boolean;
}

/** The feature units an administrator can switch off — the cases of App\Support\Feature. */
export type FeatureKey = 'diary' | 'directMessage' | 'timeline' | 'group' | 'groupTopic' | 'groupEvent' | 'groupTalk' | 'friend' | 'mcp';

export interface PageProps {
    name: string;
    auth: {
        user: AuthUser | null;
    };
    snsLogo: SnsLogo;
    /** Dependencies already resolved server-side. Hiding here is presentation only — a switched-off
     *  unit's rows never reach the payload. */
    enabledFeatures: Record<FeatureKey, boolean>;
    /** The server's `App\Support\Look`; `standard` for a guest whatever the site says. */
    look: LookId;
    unread: UnreadCounts | null;
    rightRail: RightRail | null;
    /** Null for a guest and while `groupTalk` is off. */
    talkNavRooms: TalkNavRooms | null;
    /** Null when push is unavailable here: a guest, or a site with no VAPID keypair. */
    push: { vapidPublicKey: string } | null;
    flash: {
        status: string | null;
        error: string | null;
    };
    locale: string;
    /** Site timezone as an IANA name; every instant is displayed in it. */
    timezone: string;
    terms: Record<string, string>;
    [key: string]: unknown;
}
