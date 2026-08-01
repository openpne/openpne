import { Link, usePage } from '@inertiajs/react';
import { AvatarMenu } from '@/components/avatar-menu';
import { BrandMark } from '@/components/brand-mark';
import { NavDrawer } from '@/components/nav-drawer';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';

/** Mobile (< lg) top bar: hamburger nav + brand + account menu for a member; brand + sign-in link
 *  for a guest (no member nav to open). */
export function TopNav() {
    const t = useT();
    const { name, auth } = usePage<PageProps>().props;

    return (
        // Height read from `--modern-top-offset` rather than restated: the var *is* this bar's height,
        // now that the top inset (a standalone PWA draws under the status bar) is part of it.
        <header className="sticky top-0 z-20 flex h-[var(--modern-top-offset)] items-center gap-2 border-b border-border bg-background/90 pt-[env(safe-area-inset-top)] pr-[calc(0.75rem+env(safe-area-inset-right))] pl-[calc(0.75rem+env(safe-area-inset-left))] backdrop-blur lg:hidden">
            {auth.user && <NavDrawer />}
            <Link href="/dashboard" className="flex min-w-0 flex-1 items-center gap-2">
                <BrandMark size="sm" />
                <span className="truncate font-bold">{name}</span>
            </Link>
            {auth.user ? (
                <AvatarMenu user={auth.user} compact />
            ) : (
                <Link
                    href="/login"
                    className="shrink-0 rounded-full px-3 py-1.5 text-sm font-medium text-link transition hover:bg-accent"
                >
                    {t('Log In')}
                </Link>
            )}
        </header>
    );
}
