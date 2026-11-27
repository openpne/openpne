import { Head, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { REAUTH_DATA, ReauthFields } from '@/components/reauth-fields';
import { Button } from '@/components/ui/button';
import { FormActions } from '@/components/ui/field';
import { AuthLayout } from '@/layouts/auth-layout';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';

type Props = { csrfToken: string };

// A native form rather than useForm: the answer is a panel page, which must load in full.
export default function AdminEntry({ csrfToken }: Props) {
    const t = useT();
    const { errors } = usePage<PageProps>().props;
    const [submitting, setSubmitting] = useState(false);
    const [reauth, setReauth] = useState(REAUTH_DATA);
    // These errors are page props, which only the next page load replaces.
    const [passwordErrorSeen, setPasswordErrorSeen] = useState(false);

    return (
        <AuthLayout title={t('Admin panel')}>
            <Head title={t('Admin panel')} />

            <p className="text-sm text-muted-foreground">{t('Please confirm it is you before opening the admin panel.')}</p>

            <form method="post" action="/member/admin-entry" className="space-y-4" onSubmit={() => setSubmitting(true)}>
                <input type="hidden" name="_token" value={csrfToken} />

                <ReauthFields
                    idPrefix="admin_entry"
                    purpose="admin-entry"
                    native
                    autoFocus
                    data={reauth}
                    errors={passwordErrorSeen ? { ...errors, current_password: undefined } : errors}
                    onChange={(field, value) => setReauth((data) => ({ ...data, [field]: value }))}
                    onProved={() => setPasswordErrorSeen(true)}
                    onResetSecondFactor={() => setReauth((data) => ({ ...data, code: '', recovery_code: '' }))}
                />

                <FormActions className="justify-end">
                    <Button type="submit" loading={submitting}>
                        {t('Open the admin panel')}
                    </Button>
                </FormActions>
            </form>
        </AuthLayout>
    );
}
