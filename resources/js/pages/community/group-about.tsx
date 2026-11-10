import { Link } from '@inertiajs/react';
import { AiChip } from '@/components/ai-chip';
import { CivilDate } from '@/components/timestamp';
import { useT } from '@/lib/i18n';
import type { GroupAbout as GroupAboutData, GroupStaffRef } from './types';

export function GroupAbout({ about }: { about: GroupAboutData }) {
    const t = useT();
    const term = 'text-muted-foreground';
    const member = (ref: GroupStaffRef) => (
        <span className="inline-flex flex-wrap items-center gap-1">
            <Link href={`/member/${ref.id}`} className="text-link hover:underline">
                {ref.name}
            </Link>
            <AiChip isAi={ref.isAi} />
        </span>
    );

    return (
        <dl className="grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-1 text-sm">
            {about.admin && (
                <>
                    <dt className={term}>{t('Administrator')}</dt>
                    <dd className="break-words">{member(about.admin)}</dd>
                </>
            )}
            {about.subAdmins.length > 0 && (
                <>
                    <dt className={term}>{t('Sub Administrator')}</dt>
                    <dd>
                        <ul className="flex flex-wrap gap-x-3">
                            {about.subAdmins.map((ref) => (
                                <li key={ref.id} className="break-words">
                                    {member(ref)}
                                </li>
                            ))}
                        </ul>
                    </dd>
                </>
            )}
            <dt className={term}>{t('Register policy')}</dt>
            <dd>{t(about.registerPolicy)}</dd>
            <dt className={term}>{t('Date Created')}</dt>
            <dd>
                <CivilDate value={about.createdOn} />
            </dd>
        </dl>
    );
}
