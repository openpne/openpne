import { Head, Link, router, usePage } from '@inertiajs/react';
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
}

interface PendingProps extends PageProps {
    community: CommunitySummary;
    applicants: {
        data: Applicant[];
        meta: { currentPage: number; lastPage: number; perPage: number; total: number };
    };
}

export default function CommunityPending() {
    const t = useT();
    const { community, applicants } = usePage<PendingProps>().props;

    const act = (path: 'approve' | 'decline', memberId: number) =>
        router.post(`/m/community/${community.id}/${path}`, { member_id: memberId }, { preserveScroll: true });

    return (
        <>
            <Head title={t('Pending members')} />
            <h1 className="break-words text-xl font-semibold text-foreground">
                <Link href={`/m/community/${community.id}`} className="hover:underline">
                    {community.name}
                </Link>
                {' — '}
                {t('Pending members')}
            </h1>

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
                                    <Avatar id={applicant.id} name={applicant.name} src={applicant.imageUrl} size="md" decorative />
                                    <Link href={`/m/member/${applicant.id}`} className="min-w-0 flex-1 truncate text-link hover:underline">
                                        {applicant.name}
                                    </Link>
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
