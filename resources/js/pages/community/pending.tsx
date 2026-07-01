import { Head, Link, router, usePage } from '@inertiajs/react';
import { Avatar } from '@/components/avatar';
import { Pagination } from '@/components/pagination';
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
                <h1 className="text-2xl font-semibold">
                    <Link href={`/m/community/${community.id}`} className="hover:underline">
                        {community.name}
                    </Link>
                    {' — '}
                    {t('Pending members')}
                </h1>

                {flash.status && <p role="status">{flash.status}</p>}
                {flash.error && <p role="alert">{flash.error}</p>}

                {applicants.data.length === 0 ? (
                    <p>{t('No pending requests.')}</p>
                ) : (
                    <>
                        <ul className="space-y-2">
                            {applicants.data.map((applicant) => (
                                <li key={applicant.id} className="flex items-center gap-3">
                                    <Avatar id={applicant.id} name={applicant.name} src={applicant.imageUrl} size="md" />
                                    <Link href={`/m/member/${applicant.id}`} className="min-w-0 flex-1 truncate hover:underline">
                                        {applicant.name}
                                    </Link>
                                    <button
                                        type="button"
                                        onClick={() => act('approve', applicant.id)}
                                        className="min-h-9 rounded-full bg-blue-600 px-4 text-sm font-medium text-white transition hover:bg-blue-700"
                                    >
                                        {t('Approve')}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => act('decline', applicant.id)}
                                        className="min-h-9 rounded-full bg-slate-100 px-4 text-sm font-medium text-slate-700 transition hover:bg-slate-200 dark:bg-slate-700 dark:text-slate-200 dark:hover:bg-slate-600"
                                    >
                                        {t('Decline')}
                                    </button>
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
