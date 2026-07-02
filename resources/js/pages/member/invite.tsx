import { Head, useForm, usePage } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { FlashMessage } from '@/components/flash-message';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';

export default function MemberInvite() {
    const t = useT();
    // Flashed after a send: "invitation sent" or "already has an account".
    const status = usePage<PageProps>().props.flash.status;
    const { data, setData, post, processing, errors } = useForm<Record<string, string>>({
        email: '',
        message: '',
    });

    function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        post('/invite');
    }

    const title = t('Invite a friend');

    return (
        <>
            <Head title={title} />
            <main className="mx-auto max-w-md space-y-4 px-4 py-8">
                <h1 className="text-xl font-semibold text-foreground">{title}</h1>

                <p className="text-sm text-muted-foreground">{t('Enter an email address to send a registration link.')}</p>

                {status && <FlashMessage>{status}</FlashMessage>}

                <form onSubmit={submit} className="space-y-4">
                    <Field label={t('Email')} htmlFor="email" error={errors.email}>
                        <Input
                            id="email"
                            type="email"
                            name="email"
                            autoComplete="off"
                            required
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                        />
                    </Field>

                    <Field label={t('Message (optional)')} htmlFor="message" error={errors.message}>
                        <Textarea
                            id="message"
                            name="message"
                            rows={4}
                            value={data.message}
                            onChange={(e) => setData('message', e.target.value)}
                        />
                    </Field>

                    <Button type="submit" loading={processing} className="w-full">
                        {t('Send invitation')}
                    </Button>
                </form>
            </main>
        </>
    );
}
