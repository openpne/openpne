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
    unreadMessages: number;
    notifications: number;
    /** Groups whose talk has something new — rooms, not messages, and never a muted one. */
    groupTalks: number;
}

/** One thumbnail tile in a NineTable grid (right rail, profile digest). */
export interface NineTableItem {
    id: number;
    name: string;
    imageUrl: string | null;
    /** Member rows carry the chosen badge color (hex); group rows are always null.
     *  Required (not optional) so a serializer that forgets it fails type-check. */
    avatarColor: string | null;
    /** Whether the tile is an AI account; always false for a group row. */
    isAi: boolean;
    href: string;
}

export interface RightRail {
    /** The faces grid names its audience: the viewer's friends, or an SNS-wide sample while the
     *  `friend` unit is switched off. Heading and view-all link follow the kind. */
    people: {
        kind: 'friends' | 'members';
        items: NineTableItem[];
    };
}

/** One room in the desktop nav's list. `id` is the group's: the row opens its talk. */
export interface TalkNavRoom {
    id: number;
    name: string;
    imageUrl: string | null;
    unread: number;
    muted: boolean;
}

/** The sidebar's slice of the joined room list — the same order, no previews. `hasMore` is what
 *  puts a "view all" row at the foot rather than growing the sidebar. */
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
    /** Whether the mobile chrome is the unified layout's. False for a guest whatever the site says. */
    unifiedLayout: boolean;
    unread: UnreadCounts | null;
    rightRail: RightRail | null;
    /** Null for a guest and while `groupTalk` is off — the sidebar draws no room list either way. */
    talkNavRooms: TalkNavRooms | null;
    /** What this device needs to subscribe to push, or null when push is unavailable here (a guest,
     *  or a site with no VAPID keypair). Null is what the push UI hides on. */
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
