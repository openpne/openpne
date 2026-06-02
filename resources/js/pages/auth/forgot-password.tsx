import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
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

            {status && <p className="text-sm font-medium text-foreground">{status}</p>}

            <form onSubmit={submit} className="space-y-4">
                <div className="space-y-1">
                    <label htmlFor="email" className="block text-sm font-medium">
                        {t('Email')}
                    </label>
                    <input
                        id="email"
                        type="email"
                        name="email"
                        autoComplete="email"
                        autoFocus
                        required
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring"
                    />
                    {errors.email && <p className="text-sm text-destructive">{errors.email}</p>}
                </div>

                <button
                    type="submit"
                    disabled={processing}
                    className="w-full rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50"
                >
                    {t('Email password reset link')}
                </button>

                <p className="text-center text-sm text-muted-foreground">
                    <Link href="/login" className="underline">
                        {t('Back to sign in')}
                    </Link>
                </p>
            </form>
        </AuthLayout>
    );
}
