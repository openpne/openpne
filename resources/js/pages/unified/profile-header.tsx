import { Link, usePage } from '@inertiajs/react';
import { AiChip } from '@/components/ai-chip';
import { InitialBadge } from '@/components/initial-badge';
import { Heading } from '@/components/ui/heading';
import { Panel } from '@/components/ui/surface';
import { UserText } from '@/components/user-text';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { FeatureKey, PageProps } from '@/types';
import type { ProfileStats } from '../member/profile-blank';

export interface UnifiedProfile {
    id: number;
    name: string;
    avatarUrl: string | null;
    avatarColor: string | null;
    isAi: boolean;
    /** Self-introduction, promoted into the header; null when unset. */
    bio: string | null;
    stats: ProfileStats;
}

// Static class names so Tailwind emits them; the row shrinks as units are switched off.
const STATS_COLUMNS = ['', 'grid-cols-1', 'grid-cols-2', 'grid-cols-3', 'grid-cols-4'];

/** Tappable count + label per section: one link each so the accessible name reads "12 Friends". */
function StatsRow({ ownerId, stats }: { ownerId: number; stats: ProfileStats }) {
    const t = useT();
    const features = usePage<PageProps>().props.enabledFeatures;
    // The viewer is always the owner here, so friends and groups go to the plain hubs rather than to
    // the ?id= lens another member's page needs.
    const items: { key: string; feature: FeatureKey; label: string; count: number; href: string }[] = [
        { key: 'diaries', feature: 'diary', label: t('%Diaries%'), count: stats.diaries, href: `/diary/listMember/${ownerId}` },
        { key: 'activity', feature: 'timeline', label: t('%Activity%'), count: stats.activity, href: `/member/${ownerId}/timeline` },
        { key: 'friends', feature: 'friend', label: t('%Friends%'), count: stats.friends, href: '/friend/list' },
        { key: 'groups', feature: 'group', label: t('%Communities%'), count: stats.groups, href: '/groups/mine' },
    ];
    const shown = items.filter((item) => features[item.feature]);

    if (shown.length === 0) {
        return null;
    }

    return (
        <div className={cn('grid gap-2', STATS_COLUMNS[shown.length])}>
            {shown.map((item) => (
                <Link key={item.key} href={item.href} className="min-w-0 rounded-lg px-2 py-1.5 text-center transition-colors hover:bg-muted/40">
                    <span className="block text-lg text-foreground">{item.count}</span>
                    {/* Long sns-term labels (e.g. "Communities") must wrap, not overflow the narrow cell. */}
                    <span className="block break-words text-xs text-muted-foreground">{item.label}</span>
                </Link>
            ))}
        </div>
    );
}

/**
 * Who the page is about — the viewer themselves. The page's own h1 is the sr-only "Home", so the
 * name is an h2 however large it is painted.
 */
export function ProfileHeader({ profile }: { profile: UnifiedProfile }) {
    const t = useT();

    return (
        <Panel bodyClassName="space-y-4">
            <div className="flex items-center gap-4">
                {/* The face is the point of this layout (the member/show 80px idiom, not the 48px
                    Avatar cap); the AI signal is the chip beside the name. */}
                {profile.avatarUrl ? (
                    <img src={profile.avatarUrl} alt="" className="size-20 shrink-0 rounded-full object-cover" />
                ) : (
                    <InitialBadge aria-hidden name={profile.name} color={profile.avatarColor} className="size-20 shrink-0 rounded-full text-2xl" />
                )}
                <div className="min-w-0 flex-1">
                    <div className="flex min-w-0 items-center gap-2">
                        <Heading as="h2" variant="page">
                            {profile.name}
                        </Heading>
                        <AiChip isAi={profile.isAi} />
                    </div>
                </div>
                <Link href={`/member/${profile.id}`} className="shrink-0 text-sm text-link hover:underline">
                    {t('View my profile')}
                </Link>
            </div>

            {profile.bio && (
                <div className="whitespace-pre-wrap break-words text-sm text-foreground">
                    <UserText text={profile.bio} />
                </div>
            )}

            <StatsRow ownerId={profile.id} stats={profile.stats} />
        </Panel>
    );
}
