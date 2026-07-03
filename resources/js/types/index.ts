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
}

export interface PageProps {
    name: string;
    auth: {
        user: AuthUser | null;
    };
    snsLogo: SnsLogo;
    unread: UnreadCounts | null;
    flash: {
        status: string | null;
        error: string | null;
    };
    locale: string;
    terms: Record<string, string>;
    [key: string]: unknown;
}
