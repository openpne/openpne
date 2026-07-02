import { Head, router, usePage } from '@inertiajs/react';
import { FlashMessage } from '@/components/flash-message';
import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { PaginatedFriends } from './types';

interface ManageProps extends PageProps {
    received: PaginatedFriends;
    sent: PaginatedFriends;
}

export default function FriendManage() {
    const t = useT();
    const { received, sent, flash } = usePage<ManageProps>().props;

    function accept(requesterId: number) {
        router.post('/m/friend/accept', { requester_id: requesterId });
    }

    function reject(requesterId: number) {
        router.post('/m/friend/reject', { requester_id: requesterId });
    }

    const title = t('Pending %friend% requests');

    return (
        <>
            <Head title={title} />
            <main className="mx-auto max-w-2xl space-y-6 px-4 py-8">
                <h1 className="text-xl font-semibold text-foreground">{title}</h1>

                {flash.status && <FlashMessage>{flash.status}</FlashMessage>}
                {flash.error && <FlashMessage variant="error">{flash.error}</FlashMessage>}

                <section className="space-y-2">
                    <h2 className="text-lg font-semibold text-foreground">{t('Requests received')}</h2>
                    {received.data.length === 0 ? (
                        <p className="text-sm text-muted-foreground">{t('No pending requests.')}</p>
                    ) : (
                        <>
                            <ul className="space-y-2">
                                {received.data.map((requester) => (
                                    <li key={requester.id} className="flex items-center justify-between gap-3">
                                        <span className="min-w-0 truncate text-foreground">{requester.name}</span>
                                        <div className="flex shrink-0 gap-2">
                                            <Button type="button" size="sm" onClick={() => accept(requester.id)}>
                                                {t('Accept')}
                                            </Button>
                                            <Button type="button" size="sm" variant="secondary" onClick={() => reject(requester.id)}>
                                                {t('Reject')}
                                            </Button>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                            <Pagination meta={received.meta} pageName="received_page" />
                        </>
                    )}
                </section>

                <section className="space-y-2">
                    <h2 className="text-lg font-semibold text-foreground">{t('Requests sent')}</h2>
                    {sent.data.length === 0 ? (
                        <p className="text-sm text-muted-foreground">{t('No outgoing requests.')}</p>
                    ) : (
                        <>
                            <ul className="space-y-2">
                                {sent.data.map((target) => (
                                    <li key={target.id} className="text-foreground">{target.name}</li>
                                ))}
                            </ul>
                            <Pagination meta={sent.meta} pageName="sent_page" />
                        </>
                    )}
                </section>
            </main>
        </>
    );
}
