import { Head, usePage } from '@inertiajs/react';
import { Card, CardBody } from '@/components/card';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';

export default function Dashboard() {
    const t = useT();
    const user = usePage<PageProps>().props.auth.user;

    if (!user) {
        return null;
    }

    return (
        <>
            <Head title={t('Home')} />
            <Card>
                <CardBody>
                    <h1 className="text-xl font-semibold">{t('Hello, :name', { name: user.name })}</h1>
                    <p className="mt-2 text-sm text-slate-600 dark:text-slate-300">
                        {t('You are signed in as :email.', { email: user.email })}
                    </p>
                </CardBody>
            </Card>
        </>
    );
}
