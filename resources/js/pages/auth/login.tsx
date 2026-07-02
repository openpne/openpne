import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import 'altcha';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { AuthLayout } from '@/layouts/auth-layout';
import { useT } from '@/lib/i18n';

type Props = { registrationOpen?: boolean; captchaRequired?: boolean; challengeUrl?: string };

export default function Login({ registrationOpen = false, captchaRequired = false, challengeUrl }: Props) {
    const t = useT();
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
        altcha: '',
    });

    // Inertia submits the useForm data, not native form fields, so mirror the widget's solution
    // (carried on its statechange event) into the payload it would otherwise post itself. The widget
    // only appears once captchaRequired flips on and is remounted (widgetKey) after a failed attempt,
    // so the listener is (re)bound on those changes, not just at mount.
    const widget = useRef<HTMLElement>(null);
    const [widgetKey, setWidgetKey] = useState(0);
    useEffect(() => {
        const el = widget.current;
        if (!el) return;
        const onState = (e: Event) => setData('altcha', (e as CustomEvent<{ payload?: string }>).detail?.payload ?? '');
        el.addEventListener('statechange', onState);
        return () => el.removeEventListener('statechange', onState);
    }, [setData, captchaRequired, widgetKey]);

    function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        post('/login', {
            // The solved payload is single-use and is spent before the credential check, so a failed
            // attempt must re-solve: drop the stale payload and remount the widget for a fresh challenge.
            onError: () => {
                setData('altcha', '');
                setWidgetKey((k) => k + 1);
            },
            onFinish: () => reset('password'),
        });
    }

    const signIn = t('Sign in');

    return (
        <AuthLayout title={signIn}>
            <Head title={signIn} />

            <form onSubmit={submit} className="space-y-4">
                <Field label={t('Email')} htmlFor="email" error={errors.email}>
                    <Input id="email" type="email" name="email" autoComplete="email" autoFocus required value={data.email} onChange={(e) => setData('email', e.target.value)} />
                </Field>

                <Field label={t('Password')} htmlFor="password" error={errors.password}>
                    <Input id="password" type="password" name="password" autoComplete="current-password" required value={data.password} onChange={(e) => setData('password', e.target.value)} />
                </Field>

                <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                    <label className="flex items-center gap-2 text-sm text-foreground">
                        <Checkbox name="remember" checked={data.remember} onChange={(e) => setData('remember', e.target.checked)} />
                        {t('Remember me')}
                    </label>

                    <Link href="/forgot-password" className="text-sm text-link hover:underline">
                        {t('Forgot your password?')}
                    </Link>
                </div>

                {captchaRequired && (
                    <div className="space-y-1">
                        <altcha-widget key={widgetKey} ref={widget} challenge={challengeUrl} />
                        {errors.altcha && <p className="text-sm text-destructive">{errors.altcha}</p>}
                    </div>
                )}

                <Button type="submit" loading={processing} className="w-full">
                    {signIn}
                </Button>

                {registrationOpen && (
                    <p className="text-center text-sm text-muted-foreground">
                        <Link href="/register" className="text-link hover:underline">
                            {t('Create an account')}
                        </Link>
                    </p>
                )}
            </form>
        </AuthLayout>
    );
}
