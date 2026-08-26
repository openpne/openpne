import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { CountPill } from '@/components/count-pill';

export type TabItem = {
    href: string;
    label: ReactNode;
    active: boolean;
    /** Trailing count (unread/pending); 0 or absent draws nothing. */
    count?: number;
    /** Phrase naming the count (":count … with new messages"); required whenever count can show. */
    countLabel?: string;
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
                    {/* The digits are hidden and the phrase joins this link's name, so it reads
                        "Joined, N … with new messages" — the count in words, once, on the link
                        itself (components/count-pill.tsx). */}
                    <CountPill count={item.count ?? 0} label={item.countLabel} />
                </Link>
            ))}
        </nav>
    );
}
