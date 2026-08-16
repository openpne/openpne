import { Head, Link, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { Heading } from '@/components/ui/heading';
import { Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { NineTableItem, PageProps } from '@/types';
import type { DiarySummary } from '../diary/types';
import { ActionTiles } from './action-tiles';
import { DiaryCards } from './diary-cards';
import { GroupGrid, type HomeGroup } from './group-grid';
import { PeopleRow } from './people-row';
import { type HomePhoto, PhotoGrid } from './photo-grid';
import { ProfileHeader, type UnifiedProfile } from './profile-header';

interface UnifiedHomeProps extends PageProps {
    profile: UnifiedProfile;
    groups: HomeGroup[];
    friends: NineTableItem[];
    recentPhotos: HomePhoto[];
    recentDiaries: DiarySummary[];
}

/** The way out of a section into the whole of it. The chevron is the affordance; the words are the promise. */
function SectionLink({ href, children }: { href: string; children: ReactNode }) {
    return (
        <Link href={href} className="flex shrink-0 items-center gap-0.5 text-xs text-link hover:underline">
            {children}
            <ChevronRight className="size-3.5" aria-hidden />
        </Link>
    );
}

/** A titled block inside a section — the two halves of "recent". */
function SubSection({ title, right, children }: { title: string; right?: ReactNode; children: ReactNode }) {
    return (
        <section>
            <div className="mb-2 flex items-center gap-2">
                <Heading as="h3" variant="label" className="min-w-0 flex-1 truncate">
                    {title}
                </Heading>
                {right}
            </div>
            {children}
        </section>
    );
}

/**
 * The unified home (SnsSettingKey::ModernUnifiedHome), read vertically: who you are, who you are here
 * with, what you have posted lately, where you can go. The digest dashboard's sections are
 * deliberately absent — the attention counts they carried are on the action tiles' badges.
 */
export default function UnifiedHome() {
    const t = useT();
    const { auth, profile, groups, friends, recentPhotos, recentDiaries, enabledFeatures } = usePage<UnifiedHomeProps>().props;
    const user = auth.user;

    if (!user) {
        return null;
    }

    const diaries = enabledFeatures.diary && recentDiaries.length > 0;
    const photos = recentPhotos.length > 0;

    return (
        <>
            <Head title={t('Home')} />
            <h1 className="sr-only">{t('Home')}</h1>

            <ProfileHeader profile={profile} />

            {/* Every section below arrives empty once its unit is off; the checks keep a heading and
                its deep link from outliving the rows. */}
            {groups.length > 0 && (
                <Panel title={t('My %communities%')} right={<SectionLink href="/groups/mine">{t('View all')}</SectionLink>}>
                    <GroupGrid groups={groups} />
                </Panel>
            )}

            {friends.length > 0 && (
                <Panel title={t('People around you')} right={<SectionLink href="/friend/list">{t('View all')}</SectionLink>}>
                    <PeopleRow people={friends} />
                </Panel>
            )}

            {(photos || diaries) && (
                <Panel title={t('Recent moments')} bodyClassName="space-y-5">
                    {photos && (
                        <SubSection title={t('Recent photos')}>
                            <PhotoGrid photos={recentPhotos} />
                        </SubSection>
                    )}
                    {diaries && (
                        <SubSection
                            title={t('My recent %diaries%')}
                            right={<SectionLink href={`/diary/listMember/${user.id}`}>{t('More')}</SectionLink>}
                        >
                            <DiaryCards diaries={recentDiaries} />
                        </SubSection>
                    )}
                </Panel>
            )}

            <Panel title={t('Menu')}>
                <ActionTiles memberId={user.id} />
            </Panel>
        </>
    );
}
