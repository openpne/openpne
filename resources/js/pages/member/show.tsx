import { Head, Link, usePage } from '@inertiajs/react';
import { Mail, UserPlus } from 'lucide-react';
import type { ReactNode } from 'react';
import { InitialBadge } from '@/components/initial-badge';
import { NineTable } from '@/components/nine-table';
import { Heading } from '@/components/ui/heading';
import { UserText } from '@/components/user-text';
import { ActionLink } from '@/components/ui/action-link';
import { List, Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { FeatureKey, NineTableItem, PageProps } from '@/types';
import { DiaryRow } from '../diary/diary-row';
import type { DiarySummary } from '../diary/types';
import { profileIsBlank } from './profile-blank';
import type { ProfileStats } from './profile-blank';

interface ProfileField {
    name: string;
    caption: string;
    value: string;
}

interface ProfilePage {
    owner: { id: number; name: string; avatarUrl: string | null; avatarColor: string | null };
    isSelf: boolean;
    age: number | null;
    /** null = own profile or guest viewer. */
    friendStatus: 'friend' | 'sent' | 'received' | 'none' | null;
    /** Self-introduction promoted into the header; null when absent or not visible. */
    bio: string | null;
    fields: ProfileField[];
}

/** null for a guest — the digest links to auth-only routes, so it is served to signed-in viewers only. */
interface ProfileDigest {
    stats: ProfileStats;
    recentDiaries: DiarySummary[];
    friends: NineTableItem[];
    groups: NineTableItem[];
}

interface ShowProps extends PageProps {
    profile: ProfilePage;
    digest: ProfileDigest | null;
}

// Static class names so Tailwind emits them; the row shrinks as units are switched off.
const STATS_COLUMNS = ['', 'grid-cols-1', 'grid-cols-2', 'grid-cols-3', 'grid-cols-4'];

/** Tappable count + label per section: one link each so the accessible name reads "12 Friends". */
function StatsRow({ ownerId, stats }: { ownerId: number; stats: ProfileStats }) {
    const t = useT();
    const features = usePage<PageProps>().props.enabledFeatures;
    const items: { key: string; feature: FeatureKey; label: string; count: number; href: string }[] = [
        { key: 'diaries', feature: 'diary', label: t('%Diaries%'), count: stats.diaries, href: `/diary/listMember/${ownerId}` },
        { key: 'activity', feature: 'timeline', label: t('%Activity%'), count: stats.activity, href: `/member/${ownerId}/timeline` },
        { key: 'friends', feature: 'friend', label: t('%Friends%'), count: stats.friends, href: `/friend/list?id=${ownerId}` },
        { key: 'groups', feature: 'group', label: t('%Communities%'), count: stats.groups, href: `/groups/mine?id=${ownerId}` },
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

/** Titled digest section with a "View all" link. `flush` for a List body; padded otherwise (grids). */
function SectionPanel({ title, viewAllHref, flush, children }: { title: string; viewAllHref: string; flush?: boolean; children: ReactNode }) {
    const t = useT();
    return (
        <Panel
            flush={flush}
            title={title}
            right={
                <Link href={viewAllHref} className="shrink-0 text-xs text-link hover:underline">
                    {t('View all')}
                </Link>
            }
        >
            {children}
        </Panel>
    );
}

export default function MemberShow() {
    const t = useT();
    const { auth, profile, digest, enabledFeatures } = usePage<ShowProps>().props;
    const { owner, fields, isSelf, age, friendStatus, bio } = profile;

    const nothingToShow = profileIsBlank(bio, age, fields.length, digest?.stats ?? null);
    // friendStatus is already null while friends are off; 'friend' is the one status with no entry
    // to offer, so listing the states keeps the row from rendering empty.
    const friendEntry = enabledFeatures.friend && ['none', 'sent', 'received'].includes(friendStatus ?? '');
    const messageEntry = enabledFeatures.directMessage;

    return (
        <>
            <Head title={owner.name} />

            <Panel bodyClassName="space-y-4">
                <div className="flex items-center gap-4">
                    {/* For the viewer's own profile the avatar block also entry-points the image editor —
                        shown even without an avatar yet, so a first image can be set. */}
                    <div className="flex flex-col items-center gap-1">
                        {owner.avatarUrl ? (
                            <img src={owner.avatarUrl} alt="" className="size-20 rounded-full object-cover" />
                        ) : (
                            <InitialBadge aria-hidden name={owner.name} color={owner.avatarColor} className="size-20 rounded-full text-2xl" />
                        )}
                        {isSelf && (
                            <Link href="/member/avatar" className="text-xs text-link hover:underline">
                                {t('Edit profile image')}
                            </Link>
                        )}
                    </div>
                    <div className="min-w-0 flex-1">
                        <Heading variant="page">{owner.name}</Heading>
                        {age !== null && <p className="mt-0.5 text-sm text-muted-foreground">{t(':age years old', { age })}</p>}
                    </div>
                    {isSelf && (
                        <Link href="/member/edit/profile" className="shrink-0 text-sm text-link hover:underline">
                            {t('Edit Profile')}
                        </Link>
                    )}
                </div>

                {bio && (
                    <div className="whitespace-pre-wrap break-words text-sm text-foreground">
                        <UserText text={bio} />
                    </div>
                )}

                {/* Primary relationship actions. Gated on an authenticated viewer: the targets need a
                    login, so a guest must not see them. Friend request is primary, message secondary. */}
                {auth.user && !isSelf && (friendEntry || messageEntry) && (
                    <div className="flex flex-wrap items-center gap-3">
                        {friendEntry && (
                            <>
                                {friendStatus === 'none' && (
                                    <ActionLink href={`/friend/link?id=${owner.id}`}>
                                        <UserPlus className="size-4" strokeWidth={2.25} aria-hidden />
                                        {t('Send a %friend% request')}
                                    </ActionLink>
                                )}
                                {friendStatus === 'sent' && (
                                    <Link href="/friend/requests" className="text-sm text-muted-foreground hover:underline">
                                        {t('%Friend% request pending.')}
                                    </Link>
                                )}
                                {friendStatus === 'received' && (
                                    <Link href="/friend/requests" className="text-sm text-link hover:underline">
                                        {t(':name sent you a %friend% request.', { name: owner.name })}
                                    </Link>
                                )}
                            </>
                        )}
                        {messageEntry && (
                            <ActionLink href={`/messages/${owner.id}`} variant="outline">
                                <Mail className="size-4" strokeWidth={2.25} aria-hidden />
                                {t('Send a message')}
                            </ActionLink>
                        )}
                    </div>
                )}

                {digest && <StatsRow ownerId={owner.id} stats={digest.stats} />}
            </Panel>

            {/* Structured profile fields are self-declared identity, so they sit next to the header
                (Facebook's Intro card, Mastodon's fields under the bio, mixi's profile table) with
                the activity previews after. */}
            {fields.length > 0 ? (
                <Panel>
                    <dl className="divide-y divide-border">
                        {fields.map((field) => (
                            <div key={field.name} className="flex gap-4 py-2 text-sm">
                                <dt className="w-40 shrink-0 text-muted-foreground">{field.caption}</dt>
                                <dd className="whitespace-pre-wrap break-words text-foreground">
                                    <UserText text={field.value} />
                                </dd>
                            </div>
                        ))}
                    </dl>
                </Panel>
            ) : nothingToShow ? (
                <Panel>
                    <p className="text-sm text-muted-foreground">{t('No profile to show.')}</p>
                </Panel>
            ) : null}

            {/* Each preview arrives empty once its unit is off; the check keeps its heading and
                "View all" from outliving the rows. */}
            {enabledFeatures.diary && digest && digest.recentDiaries.length > 0 && (
                <SectionPanel flush title={t('Recent %diaries%')} viewAllHref={`/diary/listMember/${owner.id}`}>
                    <List>
                        {digest.recentDiaries.map((diary) => (
                            <DiaryRow key={diary.id} diary={diary} rich />
                        ))}
                    </List>
                </SectionPanel>
            )}

            {enabledFeatures.friend && digest && digest.friends.length > 0 && (
                <SectionPanel title={t('%Friends% (:count)', { count: digest.stats.friends })} viewAllHref={`/friend/list?id=${owner.id}`}>
                    <NineTable items={digest.friends} shape="round" columns={5} />
                </SectionPanel>
            )}

            {enabledFeatures.group && digest && digest.groups.length > 0 && (
                <SectionPanel
                    title={t('Joined %communities% (:count)', { count: digest.stats.groups })}
                    viewAllHref={`/groups/mine?id=${owner.id}`}
                >
                    <NineTable items={digest.groups} shape="square" columns={5} />
                </SectionPanel>
            )}

        </>
    );
}
