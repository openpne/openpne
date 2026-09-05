import { Link, usePage } from '@inertiajs/react';
import { LogIn, Pencil } from 'lucide-react';
import { AvatarMenu } from '@/components/avatar-menu';
import { BrandMark } from '@/components/brand-mark';
import { BrandName } from '@/components/brand-name';
import { NavItems } from '@/components/nav-items';
import { ActionLink } from '@/components/ui/action-link';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';

export function LeftNav() {
    const t = useT();
    const { auth, enabledFeatures, talkNavRooms } = usePage<PageProps>().props;

    return (
        // Full-height at lg+, so on a tablet running the installed app its first and last rows would
        // sit under the status bar / home indicator without the insets.
        <aside className="sticky top-0 hidden h-dvh w-64 shrink-0 flex-col gap-2 border-r border-border px-2 py-4 pt-[calc(1rem+env(safe-area-inset-top))] pb-[calc(1rem+env(safe-area-inset-bottom))] lg:flex">
            <Link
                href="/"
                className="flex min-h-11 items-center gap-3 rounded-full px-2 transition hover:bg-accent"
            >
                <BrandMark size="md" />
                <BrandName size="lg" className="truncate" />
            </Link>
            {auth.user ? (
                <>
                    <nav className="flex-1 overflow-y-auto">
                        <NavItems rooms={talkNavRooms} />
                    </nav>
                    {/* The desktop counterpart of the dashboard's action FAB — it follows the same unit. */}
                    {enabledFeatures.diary && (
                        <ActionLink href="/diary/new" className="rounded-full">
                            <Pencil className="size-5" strokeWidth={2.25} />
                            {t('Post %diary%')}
                        </ActionLink>
                    )}
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
