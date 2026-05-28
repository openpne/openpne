import { Head, router, usePage } from '@inertiajs/react';
import { Pagination } from '@/components/pagination';
import type { PageProps } from '@/types';
import type { PaginatedFriends } from './types';

interface ManageProps extends PageProps {
    received: PaginatedFriends;
    sent: PaginatedFriends;
}

export default function FriendManage() {
    const { received, sent, flash } = usePage<ManageProps>().props;

    function accept(requesterId: number) {
        router.post('/m/friend/accept', { requester_id: requesterId });
    }

    function reject(requesterId: number) {
        router.post('/m/friend/reject', { requester_id: requesterId });
    }

    return (
        <>
            <Head title="Pending friend requests" />
            <main className="mx-auto max-w-2xl space-y-6 px-4 py-8">
                <h1 className="text-2xl font-semibold">Pending friend requests</h1>

                {flash.status && <p role="status">{flash.status}</p>}
                {flash.error && <p role="alert">{flash.error}</p>}

                <section className="space-y-2">
                    <h2 className="text-lg font-semibold">Requests received</h2>
                    {received.data.length === 0 ? (
                        <p>No pending requests.</p>
                    ) : (
                        <>
                            <ul className="space-y-2">
                                {received.data.map((requester) => (
                                    <li key={requester.id} className="flex items-center justify-between">
                                        <span>{requester.name}</span>
                                        <div className="space-x-2">
                                            <button type="button" onClick={() => accept(requester.id)}>
                                                Accept
                                            </button>
                                            <button type="button" onClick={() => reject(requester.id)}>
                                                Reject
                                            </button>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                            <Pagination meta={received.meta} pageName="received_page" />
                        </>
                    )}
                </section>

                <section className="space-y-2">
                    <h2 className="text-lg font-semibold">Requests sent</h2>
                    {sent.data.length === 0 ? (
                        <p>No outgoing requests.</p>
                    ) : (
                        <>
                            <ul className="space-y-2">
                                {sent.data.map((target) => (
                                    <li key={target.id}>{target.name}</li>
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
