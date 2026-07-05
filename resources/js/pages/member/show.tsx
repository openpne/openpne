import { Head, Link, usePage } from '@inertiajs/react';
import { List, ListRow, Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface ProfileField {
    name: string;
    caption: string;
    value: string;
}

interface ProfilePage {
    owner: { id: number; name: string; avatarUrl: string | null };
    isSelf: boolean;
    age: number | null;
    fields: ProfileField[];
}

interface ShowProps extends PageProps {
    profile: ProfilePage;
}

export default function MemberShow() {
    const t = useT();
    const { profile } = usePage<ShowProps>().props;
    const { owner, fields, isSelf, age } = profile;

    return (
        <main className="mx-auto max-w-2xl space-y-6 px-4 py-8">
            <Head title={owner.name} />

            <Panel>
                <div className="flex items-center gap-4">
                    {/* For the viewer's own profile the avatar block also entry-points the image editor —
                        shown even without an avatar yet, so a first image can be set. */}
                    <div className="flex flex-col items-center gap-1">
                        {owner.avatarUrl && (
                            <img src={owner.avatarUrl} alt="" className="size-20 rounded-md object-cover" />
                        )}
                        {isSelf && (
                            <Link href="/m/member/avatar" className="text-xs text-link hover:underline">
                                {t('Edit profile image')}
                            </Link>
                        )}
                    </div>
                    <h1 className="break-words text-xl font-semibold text-foreground">{owner.name}</h1>
                    {isSelf ? (
                        <Link href="/m/member/edit/profile" className="text-sm text-link hover:underline">
                            {t('Edit Profile')}
                        </Link>
                    ) : (
                        <Link href={`/m/message/sendToFriend?id=${owner.id}`} className="text-sm text-link hover:underline">
                            {t('Send a message')}
                        </Link>
                    )}
                </div>
            </Panel>

            {/* Jump to the owner's own content — same links whether the profile is the viewer's or not
                (OpenPNE 3 profile parity). The list/joined routes accept ?id so they scope to this owner. */}
            <Panel flush>
                <List>
                    <ListRow href={`/m/diary/listMember/${owner.id}`} chevron>
                        <span className="min-w-0 flex-1 text-sm text-foreground">{t('%Diary%')}</span>
                    </ListRow>
                    <ListRow href={`/m/member/${owner.id}/timeline`} chevron>
                        <span className="min-w-0 flex-1 text-sm text-foreground">{t('%Activity%')}</span>
                    </ListRow>
                    <ListRow href={`/m/friend/list?id=${owner.id}`} chevron>
                        <span className="min-w-0 flex-1 text-sm text-foreground">{t('%Friends%')}</span>
                    </ListRow>
                    <ListRow href={`/m/community/joined?id=${owner.id}`} chevron>
                        <span className="min-w-0 flex-1 text-sm text-foreground">{t('%Communities%')}</span>
                    </ListRow>
                </List>
            </Panel>

            {age === null && fields.length === 0 ? (
                <Panel>
                    <p className="text-sm text-muted-foreground">{t('No profile to show.')}</p>
                </Panel>
            ) : (
                <Panel>
                    <dl className="divide-y divide-border">
                        {age !== null && (
                            <div className="flex gap-4 py-2 text-sm">
                                <dt className="w-40 shrink-0 font-medium text-muted-foreground">{t('Age')}</dt>
                                <dd className="text-foreground">{t(':age years old', { age })}</dd>
                            </div>
                        )}
                        {fields.map((field) => (
                            <div key={field.name} className="flex gap-4 py-2 text-sm">
                                <dt className="w-40 shrink-0 font-medium text-muted-foreground">{field.caption}</dt>
                                <dd className="text-foreground">{field.value}</dd>
                            </div>
                        ))}
                    </dl>
                </Panel>
            )}
        </main>
    );
}
