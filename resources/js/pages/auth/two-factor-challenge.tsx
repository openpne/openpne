import { Head, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { OtpInput } from '@/components/ui/otp-input';
import { AuthLayout } from '@/layouts/auth-layout';
import { useT } from '@/lib/i18n';

// Second login step for a member with TOTP enabled. The challenged member is carried in the
// session, so there is no identifier field. Only the active field is submitted: the server
// prefers a filled recovery_code over code, so the inactive one must be cleared on toggle.
export default function TwoFactorChallenge() {
    const t = useT();
    const [useRecovery, setUseRecovery] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        code: '',
        recovery_code: '',
    });

    function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        post('/two-factor-challenge');
    }

    function toggle() {
        reset();
        setUseRecovery((v) => !v);
    }

    const title = t('Two-factor authentication');

    return (
        <AuthLayout title={title}>
            <Head title={title} />

            <form onSubmit={submit} className="space-y-4">
                {useRecovery ? (
                    <Field
                        label={t('Recovery code')}
                        htmlFor="recovery_code"
                        help={t('Each recovery code can be used once, if you no longer have your authenticator.')}
                        error={errors.recovery_code}
                    >
                        <Input
                            id="recovery_code"
                            type="text"
                            name="recovery_code"
                            autoComplete="off"
                            autoFocus
                            required
                            value={data.recovery_code}
                            onChange={(e) => setData('recovery_code', e.target.value)}
                        />
                    </Field>
                ) : (
                    <Field
                        label={t('Authentication code')}
                        htmlFor="code"
                        help={t('Enter the six-digit code shown in your authenticator app.')}
                        error={errors.code}
                    >
                        <OtpInput autoFocus value={data.code} onChange={(code) => setData('code', code)} />
                    </Field>
                )}

                <Button type="submit" loading={processing} className="w-full">
                    {t('Sign in')}
                </Button>

                <p className="text-center text-sm">
                    <button type="button" onClick={toggle} className="text-link hover:underline">
                        {useRecovery ? t('Use an authentication code instead') : t('Use a recovery code instead')}
                    </button>
                </p>
            </form>
        </AuthLayout>
    );
}
