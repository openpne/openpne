import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { AuthLayout } from '@/layouts/auth-layout';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';

type Props = PageProps & { token: string; newEmail: string };

// Token landing reachable logged-in or out; the cancellation is the POST below, not this GET render,
// so a prefetch cannot void the change.
export default function EmailChangeCancel() {
    const t = useT();
    const { token, newEmail } = usePage<Props>().props;
    const { post, processing } = useForm({});

    function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        post(`/member/config/email/cancel/${token}`);
    }

    const title = t('Cancel email change');

    return (
        <AuthLayout title={title}>
            <Head title={title} />

            <p className="text-sm text-muted-foreground">
                {t('Cancel the pending change of your email address to :email?', { email: newEmail })}
            </p>

            <form onSubmit={submit} className="space-y-4">
                <Button type="submit" loading={processing} className="w-full">
                    {title}
                </Button>
            </form>
        </AuthLayout>
    );
}
