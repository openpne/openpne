import { Link, router, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { BrandMark } from '@/components/brand-mark';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

// Language autonyms rendered verbatim (never translated): a reader who cannot read the current
// language must still recognise their own.
const LOCALE_OPTIONS: { value: string; label: string }[] = [
    { value: 'ja', label: '日本語' },
    { value: 'en', label: 'English' },
];

const POLICY_LINKS: { href: string; label: string }[] = [
    { href: '/terms', label: 'Terms of service' },
    { href: '/privacy', label: 'Privacy policy' },
];

// 'wide' is the member frame's standard column, so a profile form fills the same width before the
// account exists as it does in the profile editor afterwards.
const WIDTH = {
    standard: 'max-w-sm',
    wide: 'max-w-2xl',
} as const;

interface AuthLayoutProps {
    title: string;
    /** Site copy shown between the brand block and the card — outside the form, which stays the only place actions live. */
    intro?: ReactNode;
    /** Widen the whole column for a screen that asks for more than credentials. */
    width?: keyof typeof WIDTH;
    children: ReactNode;
}

/**
 * Pre-login card layout. The footer language toggle is the guest's locale entry point (POST
 * /locale stores the choice in the session; registration promotes it to members.locale). The
 * current locale is inert text — only the other language posts.
 */
export function AuthLayout({ title, intro, width = 'standard', children }: AuthLayoutProps) {
    const t = useT();
    const { locale, name } = usePage<PageProps>().props;
    // One width for brand, intro and card: they read as a single column, so they share an edge.
    const column = WIDTH[width];

    // dvh, not vh: 100vh is the URL-bar-hidden height on a phone, so centering against it drops the
    // card below the fold. The gutter is a minimum — centering supplies the rest.
    return (
        <div className="flex min-h-dvh flex-col items-center justify-center bg-muted pt-[calc(1.5rem+env(safe-area-inset-top))] pr-[calc(1rem+env(safe-area-inset-right))] pb-[calc(1.5rem+env(safe-area-inset-bottom))] pl-[calc(1rem+env(safe-area-inset-left))] sm:pt-[calc(3rem+env(safe-area-inset-top))] sm:pb-[calc(3rem+env(safe-area-inset-bottom))]">
            <header className={cn('mb-6 flex w-full flex-col items-center gap-3', column)}>
                <BrandMark size="lg" />
                <p className="max-w-full text-center text-lg font-bold break-words text-foreground">{name}</p>
            </header>
            {/* An aside, not a div: every node needs a landmark (axe region), and site copy is complementary to the sign-in task. */}
            {intro && <aside className={cn('mb-6 w-full', column)}>{intro}</aside>}
            <main className={cn('w-full space-y-6 rounded-lg border border-border bg-card p-6 shadow-sm', column)}>
                <h1 className="text-center text-xl font-semibold text-foreground">{title}</h1>
                {children}
            </main>
            {/* The policy pages a visitor has to be able to read before they decide to join. */}
            <nav aria-label={t('About this site')} className="mt-6">
                <ul className="flex items-center text-sm">
                    {POLICY_LINKS.map(({ href, label }, i) => (
                        <li key={href} className="flex items-center">
                            {i > 0 && (
                                <span aria-hidden="true" className="px-1 text-muted-foreground">
                                    ·
                                </span>
                            )}
                            <Link
                                href={href}
                                className="rounded px-2 py-1 text-muted-foreground underline-offset-4 hover:text-foreground hover:underline focus-visible:outline-2 focus-visible:outline-ring"
                            >
                                {t(label)}
                            </Link>
                        </li>
                    ))}
                </ul>
            </nav>
            <nav aria-label={t('Language')} className="mt-3">
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
