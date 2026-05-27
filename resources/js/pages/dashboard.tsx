import { Head, router, usePage } from '@inertiajs/react';
import type { PageProps } from '@/types';

export default function Dashboard() {
    const { auth } = usePage<PageProps>().props;
    const user = auth.user;

    if (!user) {
        return null;
    }

    function logout() {
        router.post('/logout');
    }

    return (
        <>
            <Head title="Dashboard" />
            <div className="min-h-screen bg-background px-4 py-12">
                <div className="mx-auto max-w-2xl space-y-6">
                    <h1 className="text-2xl font-semibold">Hello, {user.name}</h1>
                    <p className="text-muted-foreground">You are signed in as {user.email}.</p>
                    <button
                        type="button"
                        onClick={logout}
                        className="rounded-md border border-input bg-background px-4 py-2 text-sm font-medium hover:bg-muted"
                    >
                        Sign out
                    </button>
                </div>
            </div>
        </>
    );
}
