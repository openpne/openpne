import { Head, Link, router, usePage } from '@inertiajs/react';
import { AiChip } from '@/components/ai-chip';
import { Avatar } from '@/components/avatar';
import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { List, ListRow, Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { CommunitySummary } from './types';

interface Applicant {
    id: number;
    name: string;
    imageUrl: string | null;
    avatarColor: string | null;
    isAi: boolean;
}

interface PendingProps extends PageProps {
    group: CommunitySummary;
    applicants: {
        data: Applicant[];
        meta: { currentPage: number; lastPage: number; perPage: number; total: number };
    };
}

export default function CommunityPending() {
    const t = useT();
    const { group, applicants } = usePage<PendingProps>().props;

    const act = (path: 'approve' | 'decline', memberId: number) =>
        router.post(`/community/member/${path}`, { id: group.id, member_id: memberId }, { preserveScroll: true });

    return (
        <>
            <Head title={t('Pending members')} />
            {applicants.data.length === 0 ? (
                <Panel>
                    <p className="text-sm text-muted-foreground">{t('No pending requests.')}</p>
                </Panel>
            ) : (
                <>
                    <Panel flush>
                        <List>
                            {applicants.data.map((applicant) => (
                                <ListRow key={applicant.id}>
                                    <Avatar id={applicant.id} name={applicant.name} src={applicant.imageUrl} color={applicant.avatarColor} isAi={applicant.isAi} size="md" decorative />
                                    <div className="flex min-w-0 flex-1 items-center gap-1.5">
                                        <Link href={`/member/${applicant.id}`} className="min-w-0 truncate text-link hover:underline">
                                            {applicant.name}
                                        </Link>
                                        <AiChip isAi={applicant.isAi} />
                                    </div>
                                    <Button type="button" size="sm" onClick={() => act('approve', applicant.id)}>
                                        {t('Approve')}
                                    </Button>
                                    <Button type="button" size="sm" variant="secondary" onClick={() => act('decline', applicant.id)}>
                                        {t('Decline')}
                                    </Button>
                                </ListRow>
                            ))}
                        </List>
                    </Panel>
                    <Pagination meta={applicants.meta} />
                </>
            )}
        </>
    );
}
