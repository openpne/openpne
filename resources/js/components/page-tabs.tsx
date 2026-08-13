import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

export type TabItem = {
    href: string;
    label: ReactNode;
    active: boolean;
    /** Optional trailing indicator (e.g. a pending/unread count). Unused today; the API reserves it. */
    badge?: ReactNode;
};

/**
 * Canonical hub tab strip: link-based navigation between a hub's views (diary all/friends, friend
 * list/requests, group browse/joined/recent, message boxes). It is navigation — not a primary
 * action — so it sits below the page heading, never in the heading's action slot. 44px tall to match
 * the heading row so switching tabs doesn't shift the layout.
 */
export function PageTabs({ ariaLabel, items }: { ariaLabel: string; items: TabItem[] }) {
    return (
        <nav className="flex gap-1 border-b border-border" aria-label={ariaLabel}>
            {items.map((item) => (
                <Link
                    key={item.href}
                    href={item.href}
                    aria-current={item.active ? 'page' : undefined}
                    className={
                        'inline-flex min-h-11 items-center gap-1.5 border-b-2 px-4 py-2 text-sm transition-colors ' +
                        (item.active
                            ? 'border-foreground text-foreground'
                            : 'border-transparent text-muted-foreground hover:text-foreground')
                    }
                >
                    {item.label}
                    {item.badge}
                </Link>
            ))}
        </nav>
    );
}
