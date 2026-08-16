import { Link, usePage } from '@inertiajs/react';
import { UserRound } from 'lucide-react';
import { CountPill } from '@/components/count-pill';
import { useT } from '@/lib/i18n';
import { visibleNavSections } from '@/lib/member-chrome';
import type { PageProps } from '@/types';

/**
 * Where the viewer can go next, as tiles. Built from the nav registry rather than a list of its own,
 * so a tile carries the same label, icon and attention badge as its nav entry and a switched-off unit
 * drops both at once. No destination here is invented: this is the nav, laid out to be reached with a
 * thumb, plus the viewer's own profile — the one place the nav has no entry for because the chrome
 * reaches it through the avatar menu.
 *
 * The subtitles are this page's copy, keyed by href, and deliberately not in the registry: they say
 * what a member would find there, which is a thing to read while browsing a menu and noise beside a
 * nav label. An href with no entry here simply shows its label, so the registry can grow without this.
 */
export function ActionTiles({ memberId }: { memberId: number }) {
    const t = useT();
    const { enabledFeatures, unread } = usePage<PageProps>().props;

    const subtitles: Record<string, string> = {
        '/diary/list': t('Read %diaries%'),
        '/groups/mine': t('Browse %communities%'),
        '/timeline': t('See the latest %activity%'),
        '/friend/list': t('Your %friends%'),
        '/messages': t('Message members one to one'),
        '/notifications': t("What's new for you"),
        '/member/search': t('Find members'),
        '/member/config': t('Your account settings'),
    };

    const tiles = [
        { href: `/member/${memberId}`, icon: UserRound, label: t('Profile'), subtitle: t('See how your profile looks'), badge: undefined },
        ...visibleNavSections(enabledFeatures).map((section) => ({
            href: section.href,
            icon: section.icon,
            label: t(section.label.key, section.label.replacements),
            subtitle: subtitles[section.href],
            badge: section.badge,
        })),
    ];

    return (
        <ul className="grid grid-cols-2 gap-2">
            {tiles.map(({ href, icon: Icon, label, subtitle, badge }) => {
                const count = badge ? (unread?.[badge.count] ?? 0) : 0;

                return (
                    <li key={href}>
                        <Link
                            href={href}
                            className="flex min-h-16 items-center gap-3 rounded-xl border border-border px-3.5 py-3 transition-colors hover:bg-muted/40"
                        >
                            <span className="relative shrink-0">
                                <Icon className="size-5 text-foreground" strokeWidth={2} aria-hidden />
                                {/* The tile is one link named by its label, so the pill has to say what
                                    its number counts — the wording the nav entry already uses. */}
                                {badge && <CountPill count={count} label={t(badge.label.key, { count })} className="absolute -top-2 -right-3" />}
                            </span>
                            <span className="min-w-0 flex-1">
                                <span className="block truncate text-sm text-foreground">{label}</span>
                                {subtitle && <span className="block line-clamp-2 text-xs text-muted-foreground">{subtitle}</span>}
                            </span>
                        </Link>
                    </li>
                );
            })}
        </ul>
    );
}
