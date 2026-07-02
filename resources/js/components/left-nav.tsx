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
        <aside className="sticky top-0 hidden h-dvh w-64 shrink-0 flex-col gap-2 border-r border-border px-2 py-4 lg:flex">
            <Link
                href="/dashboard"
                className="flex min-h-11 items-center gap-3 rounded-full px-2 transition hover:bg-accent"
            >
                <BrandMark size="md" />
                <span className="truncate text-lg font-bold">{name}</span>
            </Link>
            {auth.user ? (
                <>
                    <nav className="flex-1 overflow-y-auto">
                        <NavItems />
                    </nav>
                    <div className="border-t border-border pt-2">
                        <AvatarMenu user={auth.user} />
                    </div>
                </>
            ) : (
                <nav className="flex-1">
                    <Link
                        href="/login"
                        className="flex min-h-11 items-center gap-3 rounded-full px-3 text-base text-foreground transition hover:bg-accent"
                    >
                        <LogIn className="size-5 shrink-0" />
                        <span>{t('Log In')}</span>
                    </Link>
                </nav>
            )}
        </aside>
    );
}
