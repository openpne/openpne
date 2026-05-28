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
    flash: {
        status: string | null;
        error: string | null;
    };
    [key: string]: unknown;
}
