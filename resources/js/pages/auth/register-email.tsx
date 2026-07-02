import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, type FormEvent } from 'react';
import 'altcha';
import { FlashMessage } from '@/components/flash-message';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { AuthLayout } from '@/layouts/auth-layout';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';

type Props = { honeypot: string; captcha: boolean; challengeUrl: string };

export default function RegisterEmail({ honeypot, captcha, challengeUrl }: Props) {
    const t = useT();
    // Shown when redirected back here from a spent/expired link (the completion route flashes status).
    const status = usePage<PageProps>().props.flash.status;
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

            {status && <FlashMessage>{status}</FlashMessage>}

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

                <Field label={t('Email')} htmlFor="email" error={errors.email}>
                    <Input id="email" type="email" name="email" autoComplete="email" autoFocus required value={data.email} onChange={(e) => setData('email', e.target.value)} />
                </Field>

                {captcha && (
                    <div className="space-y-1">
                        <altcha-widget ref={widget} challenge={challengeUrl} />
                        {errors.altcha && <p className="text-sm text-destructive">{errors.altcha}</p>}
                    </div>
                )}

                <Button type="submit" loading={processing} className="w-full">
                    {t('Send')}
                </Button>

                <p className="text-center text-sm text-muted-foreground">
                    <Link href="/login" className="text-link hover:underline">
                        {t('Back to login')}
                    </Link>
                </p>
            </form>
        </AuthLayout>
    );
}
