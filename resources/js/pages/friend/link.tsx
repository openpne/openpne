import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { FlashMessage } from '@/components/flash-message';
import { Button } from '@/components/ui/button';
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

    const title = t('Send a %friend% request');

    return (
        <>
            <Head title={title} />
            <main className="mx-auto max-w-md space-y-4 px-4 py-8">
                <h1 className="text-xl font-semibold text-foreground">{title}</h1>

                {flash.error && <FlashMessage variant="error">{flash.error}</FlashMessage>}

                <p className="text-foreground">{t('Send a %friend% request to :name?', { name: target.name })}</p>

                <div className="flex items-center gap-3">
                    <Button type="button" onClick={submit} loading={submitting}>
                        {t('Send request')}
                    </Button>
                    <Link href="/m/friend/list" className="text-sm text-link hover:underline">
                        {t('Cancel')}
                    </Link>
                </div>
            </main>
        </>
    );
}
