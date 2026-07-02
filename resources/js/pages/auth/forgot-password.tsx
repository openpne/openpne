import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { FlashMessage } from '@/components/flash-message';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { AuthLayout } from '@/layouts/auth-layout';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';

export default function ForgotPassword() {
    const t = useT();
    const status = usePage<PageProps>().props.flash.status;
    const { data, setData, post, processing, errors } = useForm({
        email: '',
    });

    function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        post('/forgot-password');
    }

    const title = t('Reset your password');

    return (
        <AuthLayout title={title}>
            <Head title={title} />

            <p className="text-sm text-muted-foreground">
                {t('Enter your email and we will send you a password reset link.')}
            </p>

            {status && <FlashMessage>{status}</FlashMessage>}

            <form onSubmit={submit} className="space-y-4">
                <Field label={t('Email')} htmlFor="email" error={errors.email}>
                    <Input id="email" type="email" name="email" autoComplete="email" autoFocus required value={data.email} onChange={(e) => setData('email', e.target.value)} />
                </Field>

                <Button type="submit" loading={processing} className="w-full">
                    {t('Email password reset link')}
                </Button>

                <p className="text-center text-sm text-muted-foreground">
                    <Link href="/login" className="text-link hover:underline">
                        {t('Back to sign in')}
                    </Link>
                </p>
            </form>
        </AuthLayout>
    );
}
