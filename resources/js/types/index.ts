export interface AuthUser {
    id: number;
    name: string;
    email: string;
}

export interface PageProps {
    name: string;
    auth: {
        user: AuthUser | null;
    };
    [key: string]: unknown;
}
