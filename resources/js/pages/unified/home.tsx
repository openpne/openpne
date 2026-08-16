import { Head, Link, usePage } from '@inertiajs/react';
import { Activity, ChevronRight, SquarePen } from 'lucide-react';
import { useT } from '@/lib/i18n';
import type { NineTableItem, PageProps } from '@/types';
import type { DiarySummary } from '../diary/types';
import { ActionTiles } from './action-tiles';
import { DiaryCards } from './diary-cards';
import { GroupGrid, type HomeGroup } from './group-grid';
import { HomeSection, SubSection } from './home-section';
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
                its deep link from outliving the rows. The accent icon is each section's own nav icon,
                so a heading and the entry it leads to carry the same mark. */}
            {groups.length > 0 && (
                <HomeSection title={t('%Communities% you belong to')} viewAll={{ label: t('View all'), href: '/groups/mine' }}>
                    <GroupGrid groups={groups} />
                </HomeSection>
            )}

            {friends.length > 0 && (
                <HomeSection title={t('People around you')} viewAll={{ label: t('View all'), href: '/friend/list' }}>
                    <PeopleRow people={friends} />
                </HomeSection>
            )}

            {(photos || diaries) && (
                <HomeSection title={t('Recent moments')} icon={Activity} bodyClassName="space-y-5">
                    {photos && (
                        <SubSection title={t('Recent photos')}>
                            <PhotoGrid photos={recentPhotos} />
                        </SubSection>
                    )}
                    {diaries && (
                        <SubSection
                            title={t('Recent %diaries%')}
                            right={
                                <Link
                                    href={`/diary/listMember/${user.id}`}
                                    className="flex shrink-0 items-center gap-0.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
                                >
                                    {t('More')}
                                    <ChevronRight className="size-4" aria-hidden />
                                </Link>
                            }
                        >
                            <DiaryCards diaries={recentDiaries} />
                        </SubSection>
                    )}
                </HomeSection>
            )}

            <HomeSection title={t('Actions')} icon={SquarePen}>
                <ActionTiles memberId={user.id} />
            </HomeSection>
        </>
    );
}
