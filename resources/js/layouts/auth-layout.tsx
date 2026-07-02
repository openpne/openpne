import type { ReactNode } from 'react';

interface AuthLayoutProps {
    title: string;
    children: ReactNode;
}

export function AuthLayout({ title, children }: AuthLayoutProps) {
    return (
        <div className="flex min-h-screen flex-col items-center justify-center bg-muted px-4 py-12">
            <main className="w-full max-w-sm space-y-6 rounded-lg border border-border bg-card p-6 shadow-sm">
                <h1 className="text-center text-xl font-semibold text-foreground">{title}</h1>
                {children}
            </main>
        </div>
    );
}
