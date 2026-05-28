import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { AuthLayout } from '@/layouts/auth-layout';
import { useT } from '@/lib/i18n';

export default function Login() {
    const t = useT();
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        post('/login', {
            onFinish: () => reset('password'),
        });
    }

    const signIn = t('Sign in');

    return (
        <AuthLayout title={signIn}>
            <Head title={signIn} />

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

                <div className="space-y-1">
                    <label htmlFor="password" className="block text-sm font-medium">
                        {t('Password')}
                    </label>
                    <input
                        id="password"
                        type="password"
                        name="password"
                        autoComplete="current-password"
                        required
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring"
                    />
                    {errors.password && <p className="text-sm text-destructive">{errors.password}</p>}
                </div>

                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        name="remember"
                        checked={data.remember}
                        onChange={(e) => setData('remember', e.target.checked)}
                        className="size-4 rounded border-input"
                    />
                    {t('Remember me')}
                </label>

                <button
                    type="submit"
                    disabled={processing}
                    className="w-full rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50"
                >
                    {signIn}
                </button>

                <p className="text-center text-sm text-muted-foreground">
                    <Link href="/register" className="underline">
                        {t('Create an account')}
                    </Link>
                </p>
            </form>
        </AuthLayout>
    );
}
