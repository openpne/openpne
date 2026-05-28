import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { PageProps } from '@/types';
import type { FriendMember } from './types';

interface LinkProps extends PageProps {
    target: FriendMember;
}

export default function FriendLink() {
    const { target, flash } = usePage<LinkProps>().props;
    const [submitting, setSubmitting] = useState(false);

    function submit() {
        setSubmitting(true);
        router.post(
            '/m/friend/link',
            { target_id: target.id },
            { onFinish: () => setSubmitting(false) },
        );
    }

    return (
        <>
            <Head title="Send friend request" />
            <main className="mx-auto max-w-md space-y-4 px-4 py-8">
                <h1 className="text-2xl font-semibold">Send a friend request</h1>

                {flash.error && <p role="alert">{flash.error}</p>}

                <p>
                    Send a friend request to <strong>{target.name}</strong>?
                </p>

                <div className="space-x-2">
                    <button type="button" onClick={submit} disabled={submitting}>
                        Send request
                    </button>
                    <Link href="/m/friend/list">Cancel</Link>
                </div>
            </main>
        </>
    );
}
