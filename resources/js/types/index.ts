export interface AuthUser {
    id: number;
    name: string;
    email: string;
    imageUrl: string | null;
    avatarColor: string | null;
}

export interface SnsLogo {
    color: string;
    url: string | null;
}

export interface UnreadCounts {
    friendRequests: number;
    unreadMessages: number;
    notifications: number;
}

/** One thumbnail tile in a NineTable grid (right rail, profile digest). */
export interface NineTableItem {
    id: number;
    name: string;
    imageUrl: string | null;
    /** Member rows carry the chosen badge color (hex); community rows are always null.
     *  Required (not optional) so a serializer that forgets it fails type-check. */
    avatarColor: string | null;
    href: string;
}

export interface RightRail {
    /** The faces grid names its audience: the viewer's friends, or an SNS-wide sample while the
     *  `friend` unit is switched off. Heading and view-all link follow the kind. */
    people: {
        kind: 'friends' | 'members';
        items: NineTableItem[];
    };
    joinedCommunities: NineTableItem[];
}

/** The feature units an administrator can switch off — the cases of App\Support\Feature. */
export type FeatureKey = 'diary' | 'message' | 'timeline' | 'community' | 'communityTopic' | 'communityEvent' | 'friend';

export interface PageProps {
    name: string;
    auth: {
        user: AuthUser | null;
    };
    snsLogo: SnsLogo;
    /** Dependencies already resolved server-side. Hiding here is presentation only — a switched-off
     *  unit's rows never reach the payload. */
    enabledFeatures: Record<FeatureKey, boolean>;
    unread: UnreadCounts | null;
    rightRail: RightRail | null;
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
