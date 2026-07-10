import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { FriendMember } from './types';

interface LinkProps extends PageProps {
    target: FriendMember;
}

export default function FriendLink() {
    const t = useT();
    const { target } = usePage<LinkProps>().props;
    const [submitting, setSubmitting] = useState(false);

    function submit() {
        setSubmitting(true);
        router.post(
            '/friend/link',
            { target_id: target.id },
            { onFinish: () => setSubmitting(false) },
        );
    }

    const title = t('Send a %friend% request');

    return (
        <>
            <Head title={title} />
            <h1 className="break-words text-xl font-semibold text-foreground">{title}</h1>

            <Panel bodyClassName="space-y-4">
                <p className="text-foreground">{t('Send a %friend% request to :name?', { name: target.name })}</p>

                <div className="flex items-center gap-3">
                    <Button type="button" onClick={submit} loading={submitting}>
                        {t('Send request')}
                    </Button>
                    <Link href="/friend/list" className="text-sm text-link hover:underline">
                        {t('Cancel')}
                    </Link>
                </div>
            </Panel>
        </>
    );
}
