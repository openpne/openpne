import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { FriendMember } from './types';

interface LinkProps extends PageProps {
    target: FriendMember;
}

export default function FriendLink() {
    const t = useT();
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

    const title = t('Send a friend request');

    return (
        <>
            <Head title={title} />
            <main className="mx-auto max-w-md space-y-4 px-4 py-8">
                <h1 className="text-2xl font-semibold">{title}</h1>

                {flash.error && <p role="alert">{flash.error}</p>}

                <p>{t('Send a friend request to :name?', { name: target.name })}</p>

                <div className="space-x-2">
                    <button type="button" onClick={submit} disabled={submitting}>
                        {t('Send request')}
                    </button>
                    <Link href="/m/friend/list">{t('Cancel')}</Link>
                </div>
            </main>
        </>
    );
}
