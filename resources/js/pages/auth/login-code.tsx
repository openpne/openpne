import { Head, router, useForm, usePage } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { FlashMessage } from '@/components/flash-message';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { OtpInput } from '@/components/ui/otp-input';
import { AuthLayout } from '@/layouts/auth-layout';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';

// The challenged member is carried in the session, so there is no identifier field.
export default function LoginCode() {
    const t = useT();
    const status = usePage<PageProps>().props.flash.status;
    const { data, setData, post, processing, errors, reset, clearErrors, setError } = useForm({ code: '' });

    function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        post('/login/code', { onError: () => reset() });
    }

    function resend() {
        reset();
        clearErrors();
        // Posted outside the form, so a refusal has to be put on the field by hand.
        router.post('/login/code/resend', {}, { onError: (refused) => setError('code', refused.code ?? '') });
    }

    const title = t('Confirmation code');

    return (
        <AuthLayout title={title}>
            <Head title={title} />

            {status && <FlashMessage>{status}</FlashMessage>}

            <form onSubmit={submit} className="space-y-4">
                <Field
                    label={t('Confirmation code')}
                    htmlFor="code"
                    help={t('Enter the six-digit code we sent to your email address.')}
                    error={errors.code}
                >
                    <OtpInput label={t('Confirmation code')} autoFocus value={data.code} onChange={(code) => setData('code', code)} />
                </Field>

                <Button type="submit" loading={processing} className="w-full">
                    {t('Sign in')}
                </Button>

                <p className="text-center text-sm">
                    <button type="button" onClick={resend} className="text-link hover:underline">
                        {t('Send a new code')}
                    </button>
                </p>
            </form>
        </AuthLayout>
    );
}
