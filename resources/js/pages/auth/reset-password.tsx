import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { AuthLayout } from '@/layouts/auth-layout';
import { useT } from '@/lib/i18n';

interface ResetPasswordProps {
    email: string;
    token: string;
}

export default function ResetPassword({ email, token }: ResetPasswordProps) {
    const t = useT();
    const { data, setData, post, processing, errors, reset } = useForm({
        token,
        email,
        password: '',
        password_confirmation: '',
    });

    function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        post('/reset-password', {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    }

    const title = t('Reset your password');

    return (
        <AuthLayout title={title}>
            <Head title={title} />

            <form onSubmit={submit} className="space-y-4">
                <Field label={t('Email')} htmlFor="email" error={errors.email}>
                    <Input id="email" type="email" name="email" autoComplete="email" readOnly value={data.email} onChange={(e) => setData('email', e.target.value)} className="bg-muted text-muted-foreground" />
                </Field>

                <Field label={t('Password')} htmlFor="password" error={errors.password}>
                    <Input id="password" type="password" name="password" autoComplete="new-password" autoFocus required value={data.password} onChange={(e) => setData('password', e.target.value)} />
                </Field>

                <Field label={t('Confirm password')} htmlFor="password_confirmation">
                    <Input id="password_confirmation" type="password" name="password_confirmation" autoComplete="new-password" required value={data.password_confirmation} onChange={(e) => setData('password_confirmation', e.target.value)} />
                </Field>

                <Button type="submit" loading={processing} className="w-full">
                    {t('Reset Password')}
                </Button>
            </form>
        </AuthLayout>
    );
}
