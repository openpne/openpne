import { Head, Link, usePage } from '@inertiajs/react';
import { List, Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import { DiaryRow } from '../diary/diary-row';
import type { DiarySummary } from '../diary/types';
import { ActionTiles } from './action-tiles';
import { type HomePhoto, PhotoGrid } from './photo-grid';
import { ProfileHeader, type UnifiedProfile } from './profile-header';

interface UnifiedHomeProps extends PageProps {
    profile: UnifiedProfile;
    recentPhotos: HomePhoto[];
    recentDiaries: DiarySummary[];
}

/**
 * The unified home (SnsSettingKey::ModernUnifiedHome), read vertically: who you are, what you have
 * posted lately, where you can go. The digest dashboard's sections are deliberately absent — the
 * attention counts they carried are on the action tiles' badges.
 */
export default function UnifiedHome() {
    const t = useT();
    const { auth, profile, recentPhotos, recentDiaries, enabledFeatures } = usePage<UnifiedHomeProps>().props;
    const user = auth.user;

    if (!user) {
        return null;
    }

    return (
        <>
            <Head title={t('Home')} />
            <h1 className="sr-only">{t('Home')}</h1>

            <ProfileHeader profile={profile} />

            {/* Both halves of "latest" arrive empty once their unit is off; the checks keep a heading
                and its deep link from outliving the rows. */}
            {recentPhotos.length > 0 && (
                <Panel title={t('Recent photos')}>
                    <PhotoGrid photos={recentPhotos} />
                </Panel>
            )}

            {enabledFeatures.diary && recentDiaries.length > 0 && (
                <Panel
                    flush
                    title={t('My recent %diaries%')}
                    right={
                        <Link href={`/diary/listMember/${user.id}`} className="shrink-0 text-xs text-link hover:underline">
                            {t('View all')}
                        </Link>
                    }
                >
                    <List>
                        {recentDiaries.map((diary) => (
                            <DiaryRow key={diary.id} diary={diary} />
                        ))}
                    </List>
                </Panel>
            )}

            <Panel title={t('Menu')}>
                <ActionTiles />
            </Panel>
        </>
    );
}
