import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { PageProps } from '@/types';
import type { FriendMember } from './types';

interface UnlinkProps extends PageProps {
    target: FriendMember;
}

export default function FriendUnlink() {
    const { target, flash } = usePage<UnlinkProps>().props;
    const [submitting, setSubmitting] = useState(false);

    function submit() {
        setSubmitting(true);
        router.post(`/friend/unlink/${target.id}`, {}, { onFinish: () => setSubmitting(false) });
    }

    return (
        <>
            <Head title="Unfriend" />
            <main className="mx-auto max-w-md space-y-4 px-4 py-8">
                <h1 className="text-2xl font-semibold">Unfriend</h1>

                {flash.error && <p role="alert">{flash.error}</p>}

                <p>
                    Remove <strong>{target.name}</strong> from your friends?
                </p>

                <div className="space-x-2">
                    <button type="button" onClick={submit} disabled={submitting}>
                        Unfriend
                    </button>
                    <Link href="/m/friend/list">Cancel</Link>
                </div>
            </main>
        </>
    );
}
