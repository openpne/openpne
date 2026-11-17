import { Head, Link, usePage } from '@inertiajs/react';
import { Activity, ChevronRight, IdCard, UserCircle2, Users } from 'lucide-react';
import { UserText } from '@/components/user-text';
import { useT } from '@/lib/i18n';
import type { NineTableItem, PageProps } from '@/types';
import type { DiarySummary } from '../diary/types';
import { DiaryCards } from '@/components/sections/diary-cards';
import { GroupGrid, type HomeGroup } from '@/components/sections/group-grid';
import { HomeSection, SubSection } from '@/components/sections/home-section';
import { MemberActions, type MemberActionsProfile } from './member-actions';
import { PeopleGrid } from '@/components/sections/people-grid';
import { type HomePhoto, PhotoGrid } from '@/components/sections/photo-grid';
import { ProfileHeader, type HeaderProfile } from '@/components/sections/profile-header';

export interface MemberPageProfile extends HeaderProfile, MemberActionsProfile {
    age: number | null;
}

interface MemberShowProps extends PageProps {
    profile: MemberPageProfile;
    fields: { name: string; caption: string; value: string }[];
    groups: HomeGroup[];
    friends: NineTableItem[];
    recentPhotos: HomePhoto[];
    recentDiaries: DiarySummary[];
}

/** Every deep link carries the owner's id, since these lists are theirs and not the viewer's. */
export default function MemberShow() {
    const t = useT();
    const { profile, fields, groups, friends, recentPhotos, recentDiaries, enabledFeatures } = usePage<MemberShowProps>().props;

    const diaries = enabledFeatures.diary && recentDiaries.length > 0;
    const photos = recentPhotos.length > 0;

    return (
        <>
            <Head title={profile.name} />

            {/* The fields below leave the self-introduction out, so clamped here it could be read nowhere. */}
            <ProfileHeader profile={profile} as="h1" clampBio={false} actions={<MemberActions profile={profile} />} />

            {/* Identity before activity. */}
            {fields.length > 0 && (
                <HomeSection title={t('Profile')} icon={IdCard}>
                    <dl className="divide-y divide-border">
                        {fields.map((field) => (
                            <div key={field.name} className="flex gap-4 py-2 text-sm">
                                <dt className="w-28 shrink-0 text-muted-foreground sm:w-40">{field.caption}</dt>
                                <dd className="min-w-0 whitespace-pre-wrap break-words text-foreground">
                                    <UserText text={field.value} />
                                </dd>
                            </div>
                        ))}
                    </dl>
                </HomeSection>
            )}

            {/* Every section below arrives empty once its unit is off; the checks keep a heading and
                its deep link from outliving the rows. */}
            {groups.length > 0 && (
                <HomeSection
                    title={t('%Communities% you belong to')}
                    icon={Users}
                    viewAll={{ label: t('View all'), href: `/groups/mine?id=${profile.id}` }}
                >
                    <GroupGrid groups={groups} />
                </HomeSection>
            )}

            {friends.length > 0 && (
                <HomeSection
                    title={t('People around you')}
                    icon={UserCircle2}
                    viewAll={{ label: t('View all'), href: `/friend/list?id=${profile.id}` }}
                >
                    <PeopleGrid people={friends} />
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
                                    href={`/diary/listMember/${profile.id}`}
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
        </>
    );
}
