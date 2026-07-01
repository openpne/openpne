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
        <header className="sticky top-0 z-20 flex h-14 items-center gap-2 border-b border-slate-200 bg-white/90 px-3 backdrop-blur dark:border-slate-700 dark:bg-slate-900/90 lg:hidden">
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
                    className="shrink-0 rounded-full px-3 py-1.5 text-sm font-medium text-blue-600 transition hover:bg-slate-100 dark:text-blue-400 dark:hover:bg-slate-800"
                >
                    {t('Log In')}
                </Link>
            )}
        </header>
    );
}
