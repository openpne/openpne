import { Link, usePage } from '@inertiajs/react';
import { BookOpen, Camera, MessageCircle, Send, UserRound, Users } from 'lucide-react';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';

/**
 * The design's six actions, verbatim — count, wording and order are the mock's, not the nav's. Looks
 * over reach is this experiment's rule: destinations the design does not draw (settings, search,
 * notifications…) live in the drawer and the bottom bar instead, and two tiles landing on the same
 * page is the design's own redundancy, kept. Badges are not drawn — attention lives on the bell and
 * the bottom bar. A tile whose unit is off drops.
 */
export function ActionTiles({ memberId }: { memberId: number }) {
    const t = useT();
    const { enabledFeatures } = usePage<PageProps>().props;

    const tiles = [
        { key: 'talk', href: '/groups/mine', icon: MessageCircle, label: t('Talk'), subtitle: t('Talk together'), on: enabledFeatures.groupTalk },
        { key: 'dm', href: '/messages', icon: Send, label: t('DM'), subtitle: t('Message members one to one'), on: enabledFeatures.directMessage },
        { key: 'profile', href: `/member/${memberId}`, icon: UserRound, label: t('My page'), subtitle: t('View my profile'), on: true },
        { key: 'diary', href: '/diary/list', icon: BookOpen, label: t('%Diaries%'), subtitle: t('Read %diaries%'), on: enabledFeatures.diary },
        { key: 'groups', href: '/groups/mine', icon: Users, label: t('%Communities%'), subtitle: t('See the %communities% you belong to'), on: enabledFeatures.group },
        { key: 'photos', href: `/member/${memberId}`, icon: Camera, label: t('Photos'), subtitle: t('See photos'), on: true },
    ].filter((tile) => tile.on);

    return (
        <ul className="grid grid-cols-2 gap-2">
            {tiles.map(({ key, href, icon: Icon, label, subtitle }) => (
                <li key={key}>
                    <Link
                        href={href}
                        // One fixed height: the design's grid is even, so the copy fits the row
                        // rather than the row growing for the copy.
                        className="flex h-16 items-center gap-2.5 rounded-xl border border-border px-3 transition-colors hover:bg-muted/40"
                    >
                        <Icon className="size-5 shrink-0 text-foreground" strokeWidth={2} aria-hidden />
                        <span className="min-w-0 flex-1">
                            <span className="block truncate text-sm text-foreground">{label}</span>
                            <span className="block truncate text-[11px] text-muted-foreground">{subtitle}</span>
                        </span>
                    </Link>
                </li>
            ))}
        </ul>
    );
}
