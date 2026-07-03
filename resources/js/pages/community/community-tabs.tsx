import { Link } from '@inertiajs/react';
import { useT } from '@/lib/i18n';

/** Browse all / Joined / Recent activity — the three viewer-scoped community views. Mirrors the
 *  diary feed's FeedTab. Rendered only in the viewer's own context (not on another member's list). */
function CommunityTab({ href, label, active }: { href: string; label: string; active: boolean }) {
    return (
        <Link
            href={href}
            aria-current={active ? 'page' : undefined}
            className={
                'min-h-11 border-b-2 px-4 py-2 text-sm font-medium transition-colors ' +
                (active
                    ? 'border-foreground text-foreground'
                    : 'border-transparent text-muted-foreground hover:text-foreground')
            }
        >
            {label}
        </Link>
    );
}

export function CommunityTabs({ active }: { active: 'browse' | 'joined' | 'recent' }) {
    const t = useT();
    return (
        <nav className="flex gap-1 border-b border-border" aria-label={t('%Communities%')}>
            <CommunityTab href="/m/community/search" label={t('All')} active={active === 'browse'} />
            <CommunityTab href="/m/community/joined" label={t('Joined')} active={active === 'joined'} />
            <CommunityTab href="/m/community/recent" label={t('Recent activity')} active={active === 'recent'} />
        </nav>
    );
}
