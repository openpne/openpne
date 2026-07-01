import { Link, usePage } from '@inertiajs/react';
import { LogIn } from 'lucide-react';
import { AvatarMenu } from '@/components/avatar-menu';
import { BrandMark } from '@/components/brand-mark';
import { NavItems } from '@/components/nav-items';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';

/** Desktop (lg+) sticky sidebar: brand (home link) + member nav + account menu; a guest (a
 *  web-public profile is reachable signed out) sees only the brand and a sign-in link. */
export function LeftNav() {
    const t = useT();
    const { name, auth } = usePage<PageProps>().props;

    return (
        <aside className="sticky top-0 hidden h-dvh w-64 shrink-0 flex-col gap-2 border-r border-slate-200 px-2 py-4 dark:border-slate-700 lg:flex">
            <Link
                href="/dashboard"
                className="flex min-h-11 items-center gap-3 rounded-full px-2 transition hover:bg-slate-100 dark:hover:bg-slate-800"
            >
                <BrandMark size="md" />
                <span className="truncate text-lg font-bold">{name}</span>
            </Link>
            {auth.user ? (
                <>
                    <nav className="flex-1 overflow-y-auto">
                        <NavItems />
                    </nav>
                    <div className="border-t border-slate-200 pt-2 dark:border-slate-700">
                        <AvatarMenu user={auth.user} />
                    </div>
                </>
            ) : (
                <nav className="flex-1">
                    <Link
                        href="/login"
                        className="flex min-h-11 items-center gap-3 rounded-full px-3 text-base text-slate-700 transition hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800"
                    >
                        <LogIn className="size-5 shrink-0" />
                        <span>{t('Log In')}</span>
                    </Link>
                </nav>
            )}
        </aside>
    );
}
