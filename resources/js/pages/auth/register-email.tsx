import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect, useRef, type FormEvent } from 'react';
import 'altcha';
import { AuthLayout } from '@/layouts/auth-layout';
import { useT } from '@/lib/i18n';

type Props = { honeypot: string; captcha: boolean; challengeUrl: string };

export default function RegisterEmail({ honeypot, captcha, challengeUrl }: Props) {
    const t = useT();
    const { data, setData, post, processing, errors } = useForm<Record<string, string>>({
        email: '',
        [honeypot]: '',
        altcha: '',
    });

    // Inertia submits the useForm data, not native form fields, so mirror the widget's solution
    // (carried on its statechange event) into the payload it would otherwise post itself.
    const widget = useRef<HTMLElement>(null);
    useEffect(() => {
        const el = widget.current;
        if (!el) return;
        const onState = (e: Event) => setData('altcha', (e as CustomEvent<{ payload?: string }>).detail?.payload ?? '');
        el.addEventListener('statechange', onState);
        return () => el.removeEventListener('statechange', onState);
    }, [setData]);

    function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        post('/register');
    }

    const title = t('Register');

    return (
        <AuthLayout title={title}>
            <Head title={title} />

            <p className="text-sm text-muted-foreground">
                {t('Enter your email and we will send you a registration link.')}
            </p>

            <form onSubmit={submit} className="space-y-4">
                {/* Honeypot: off-screen and not announced; a person never fills it, a bot does and
                    its submit is silently dropped (SpamTrap). */}
                <input
                    type="text"
                    name={honeypot}
                    value={data[honeypot] ?? ''}
                    onChange={(e) => setData(honeypot, e.target.value)}
                    tabIndex={-1}
                    autoComplete="off"
                    aria-hidden="true"
                    style={{ position: 'absolute', left: '-9999px' }}
                />

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

                {captcha && (
                    <div className="space-y-1">
                        <altcha-widget ref={widget} challenge={challengeUrl} />
                        {errors.altcha && <p className="text-sm text-destructive">{errors.altcha}</p>}
                    </div>
                )}

                <button
                    type="submit"
                    disabled={processing}
                    className="w-full rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50"
                >
                    {t('Send')}
                </button>

                <p className="text-center text-sm text-muted-foreground">
                    <Link href="/login" className="underline">
                        {t('Back to login')}
                    </Link>
                </p>
            </form>
        </AuthLayout>
    );
}
