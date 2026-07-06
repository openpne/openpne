import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { FriendMember } from './types';

interface UnlinkProps extends PageProps {
    target: FriendMember;
}

export default function FriendUnlink() {
    const t = useT();
    const { target } = usePage<UnlinkProps>().props;
    const [submitting, setSubmitting] = useState(false);

    function submit() {
        setSubmitting(true);
        router.post(`/m/friend/unlink/${target.id}`, {}, { onFinish: () => setSubmitting(false) });
    }

    const title = t('Remove %friend%');

    return (
        <>
            <Head title={title} />
            <h1 className="break-words text-xl font-semibold text-foreground">{title}</h1>

            <Panel bodyClassName="space-y-4">
                <p className="text-foreground">{t('Remove :name from your %friends%?', { name: target.name })}</p>

                <div className="flex items-center gap-3">
                    <Button type="button" variant="destructive" onClick={submit} loading={submitting}>
                        {title}
                    </Button>
                    <Link href="/m/friend/list" className="text-sm text-link hover:underline">
                        {t('Cancel')}
                    </Link>
                </div>
            </Panel>
        </>
    );
}
