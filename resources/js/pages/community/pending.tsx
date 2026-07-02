import { Head, Link, router, usePage } from '@inertiajs/react';
import { Avatar } from '@/components/avatar';
import { FlashMessage } from '@/components/flash-message';
import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
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
    const { community, applicants, flash } = usePage<PendingProps>().props;

    const act = (path: 'approve' | 'decline', memberId: number) =>
        router.post(`/m/community/${community.id}/${path}`, { member_id: memberId }, { preserveScroll: true });

    return (
        <>
            <Head title={t('Pending members')} />
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8">
                <h1 className="text-xl font-semibold text-foreground">
                    <Link href={`/m/community/${community.id}`} className="hover:underline">
                        {community.name}
                    </Link>
                    {' — '}
                    {t('Pending members')}
                </h1>

                {flash.status && <FlashMessage>{flash.status}</FlashMessage>}
                {flash.error && <FlashMessage variant="error">{flash.error}</FlashMessage>}

                {applicants.data.length === 0 ? (
                    <p className="text-sm text-muted-foreground">{t('No pending requests.')}</p>
                ) : (
                    <>
                        <ul className="space-y-2">
                            {applicants.data.map((applicant) => (
                                <li key={applicant.id} className="flex items-center gap-3">
                                    <Avatar id={applicant.id} name={applicant.name} src={applicant.imageUrl} size="md" />
                                    <Link href={`/m/member/${applicant.id}`} className="min-w-0 flex-1 truncate text-link hover:underline">
                                        {applicant.name}
                                    </Link>
                                    <Button type="button" size="sm" onClick={() => act('approve', applicant.id)}>
                                        {t('Approve')}
                                    </Button>
                                    <Button type="button" size="sm" variant="secondary" onClick={() => act('decline', applicant.id)}>
                                        {t('Decline')}
                                    </Button>
                                </li>
                            ))}
                        </ul>
                        <Pagination meta={applicants.meta} />
                    </>
                )}
            </main>
        </>
    );
}
