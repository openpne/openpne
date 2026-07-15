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
    friends: NineTableItem[];
    joinedCommunities: NineTableItem[];
}

export interface PageProps {
    name: string;
    auth: {
        user: AuthUser | null;
    };
    snsLogo: SnsLogo;
    unread: UnreadCounts | null;
    rightRail: RightRail | null;
    flash: {
        status: string | null;
        error: string | null;
    };
    locale: string;
    terms: Record<string, string>;
    [key: string]: unknown;
}
