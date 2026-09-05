import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { AuthLayout } from '@/layouts/auth-layout';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';

type Props = PageProps & { token: string };

// The reset is the POST below, not this GET render, so a mail scanner cannot consume the token; only
// the token is passed in, never the account's address or name.
export default function MfaReset() {
    const t = useT();
    const { token } = usePage<Props>().props;
    const { data, setData, post, processing, errors, reset } = useForm({ password: '' });

    function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        post(`/member/mfa/reset/${token}`, {
            onFinish: () => reset('password'),
        });
    }

    const title = t('Reset two-factor authentication');

    return (
        <AuthLayout title={title}>
            <Head title={title} />

            <p className="text-sm text-muted-foreground">
                {t('Enter your account password to turn off two-factor authentication. You will then sign in with your password alone and can set it up again.')}
            </p>

            <form onSubmit={submit} className="space-y-4">
                <Field label={t('Password')} htmlFor="password" error={errors.password}>
                    <Input id="password" type="password" name="password" autoComplete="current-password" autoFocus required value={data.password} onChange={(e) => setData('password', e.target.value)} />
                </Field>

                <Button type="submit" loading={processing} className="w-full">
                    {title}
                </Button>
            </form>
        </AuthLayout>
    );
}
