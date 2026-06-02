import { Head, usePage } from '@inertiajs/react';
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
    fields: ProfileField[];
}

interface ShowProps extends PageProps {
    profile: ProfilePage;
}

export default function MemberShow() {
    const t = useT();
    const { profile } = usePage<ShowProps>().props;
    const { owner, fields } = profile;

    return (
        <main className="mx-auto max-w-2xl space-y-6 px-4 py-8">
            <Head title={owner.name} />

            <div className="flex items-center gap-4">
                {owner.avatarUrl && (
                    <img src={owner.avatarUrl} alt={owner.name} className="size-20 rounded-md object-cover" />
                )}
                <h1 className="text-xl font-semibold text-foreground">{owner.name}</h1>
            </div>

            {fields.length === 0 ? (
                <p className="text-sm text-muted-foreground">{t('No profile to show.')}</p>
            ) : (
                <dl className="divide-y divide-border">
                    {fields.map((field) => (
                        <div key={field.name} className="flex gap-4 py-2 text-sm">
                            <dt className="w-40 shrink-0 font-medium">{field.caption}</dt>
                            <dd className="text-foreground">{field.value}</dd>
                        </div>
                    ))}
                </dl>
            )}
        </main>
    );
}
