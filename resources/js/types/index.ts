export interface AuthUser {
    id: number;
    name: string;
    email: string;
    imageUrl: string | null;
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

export interface RightRailItem {
    id: number;
    name: string;
    imageUrl: string | null;
    href: string;
}

export interface RightRail {
    friends: RightRailItem[];
    joinedCommunities: RightRailItem[];
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
