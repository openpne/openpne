import { router, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { BrandMark } from '@/components/brand-mark';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';

// Language autonyms rendered verbatim (never translated): a reader who cannot read the current
// language must still recognise their own.
const LOCALE_OPTIONS: { value: string; label: string }[] = [
    { value: 'ja', label: '日本語' },
    { value: 'en', label: 'English' },
];

interface AuthLayoutProps {
    title: string;
    /** Site copy shown between the brand block and the card — outside the form, which stays the only place actions live. */
    intro?: ReactNode;
    children: ReactNode;
}

/**
 * Pre-login card layout. The footer language toggle is the guest's locale entry point (POST
 * /locale stores the choice in the session; registration promotes it to members.locale). The
 * current locale is inert text — only the other language posts.
 */
export function AuthLayout({ title, intro, children }: AuthLayoutProps) {
    const t = useT();
    const { locale, name } = usePage<PageProps>().props;

    return (
        <div className="flex min-h-screen flex-col items-center justify-center bg-muted px-4 py-12">
            <header className="mb-6 flex w-full max-w-sm flex-col items-center gap-3">
                <BrandMark size="lg" />
                <p className="max-w-full text-center text-lg font-bold break-words text-foreground">{name}</p>
            </header>
            {/* An aside, not a div: every node needs a landmark (axe region), and site copy is complementary to the sign-in task. */}
            {intro && <aside className="mb-6 w-full max-w-sm">{intro}</aside>}
            <main className="w-full max-w-sm space-y-6 rounded-lg border border-border bg-card p-6 shadow-sm">
                <h1 className="text-center text-xl font-semibold text-foreground">{title}</h1>
                {children}
            </main>
            <nav aria-label={t('Language')} className="mt-6">
                <ul className="flex items-center text-sm">
                    {LOCALE_OPTIONS.map((opt, i) => (
                        <li key={opt.value} className="flex items-center">
                            {i > 0 && (
                                <span aria-hidden="true" className="px-1 text-muted-foreground">
                                    ·
                                </span>
                            )}
                            {opt.value === locale ? (
                                <span lang={opt.value} aria-current="true" className="px-2 py-1 font-medium text-foreground">
                                    {opt.label}
                                </span>
                            ) : (
                                <button
                                    type="button"
                                    lang={opt.value}
                                    onClick={() => router.post('/locale', { locale: opt.value })}
                                    className="rounded px-2 py-1 text-muted-foreground underline-offset-4 hover:text-foreground hover:underline focus-visible:outline-2 focus-visible:outline-ring"
                                >
                                    {opt.label}
                                </button>
                            )}
                        </li>
                    ))}
                </ul>
            </nav>
        </div>
    );
}
