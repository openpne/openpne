import { Link, usePage } from '@inertiajs/react';
import { UnreadPill } from '@/components/unread-pill';
import { bottomNavSections } from '@/lib/member-chrome';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';

/**
 * Mobile (< lg) bottom tab bar: the few sections a phone reaches for, with their unread badges on
 * the first screen. Without it the counts live only behind the hamburger, so nothing on a phone says
 * anything is waiting. The tabs stay in the drawer too — this is a shortcut, not the whole nav.
 * Members only: a guest (a web-public profile is reachable signed out) has no member nav to shortcut.
 */
export function BottomNav() {
    const t = useT();
    const { url, props } = usePage<PageProps>();

    if (!props.auth.user) {
        return null;
    }

    // Exact pathname match (strip query/hash) for the tabs that ask for one; see NavSection.exact.
    const path = url.replace(/[?#].*$/, '');

    return (
        <nav
            aria-label={t('Navigation')}
            className="fixed inset-x-0 bottom-0 z-20 border-t border-border bg-background/90 pb-[env(safe-area-inset-bottom)] backdrop-blur lg:hidden"
        >
            {/* The bar's inner height is what `--modern-bottom-offset` adds the safe-area inset to. */}
            <ul className="flex h-14 items-stretch">
                {bottomNavSections(props.enabledFeatures).map(({ href, match, exact, icon: Icon, label, badge }) => {
                    const active = exact ? path === match : path.startsWith(match);
                    const count = badge ? (props.unread?.[badge.count] ?? 0) : 0;
                    return (
                        <li key={href} className="flex-1">
                            <Link
                                href={href}
                                aria-current={active ? 'page' : undefined}
                                // Icon-only tabs, so the count belongs in the name rather than beside it.
                                aria-label={
                                    badge && count > 0 ? t(badge.label.key, { count }) : t(label.key, label.replacements)
                                }
                                className={
                                    'flex size-full min-h-12 items-center justify-center transition ' +
                                    (active ? 'text-foreground' : 'text-muted-foreground hover:text-foreground')
                                }
                            >
                                <span className="relative inline-flex">
                                    <Icon className="size-6" strokeWidth={active ? 2.25 : 2} aria-hidden />
                                    <UnreadPill count={count} className="absolute -top-2 -right-2.5" />
                                </span>
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}
