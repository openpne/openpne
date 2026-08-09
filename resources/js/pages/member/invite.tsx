import { Head, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Heading } from '@/components/ui/heading';
import { Input } from '@/components/ui/input';
import { Panel } from '@/components/ui/surface';
import { Textarea } from '@/components/ui/textarea';
import { useT } from '@/lib/i18n';

export default function MemberInvite() {
    const t = useT();
    // Flashed after a send: "invitation sent" or "already has an account".
    const { data, setData, post, processing, errors } = useForm<Record<string, string>>({
        email: '',
        message: '',
    });

    function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        post('/invite');
    }

    const title = t('Invite a new member');

    return (
        <>
            <Head title={title} />
            <Heading variant="page">{title}</Heading>

            <p className="text-sm text-muted-foreground">{t('Enter an email address to send a registration link.')}</p>

            <Panel>
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
            </Panel>
        </>
    );
}
