import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Avatar } from '@/components/avatar';
import { FlashMessage } from '@/components/flash-message';
import { Button } from '@/components/ui/button';
import { Field, FormActions } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { MessageMember } from './types';

interface ComposeProps extends PageProps {
    recipient: MessageMember;
    parentId: number | null; // reply links (OpenPNE 3 return_message_id / thread_message_id)
    threadId: number | null;
    subject: string; // prefilled on reply ("Re: …")
    body: string; // prefilled on reply (the original quoted)
}

export default function MessageCompose() {
    const t = useT();
    const { recipient, parentId, threadId, subject, body, flash } = usePage<ComposeProps>().props;

    const form = useForm({
        to: recipient.id,
        subject,
        body,
        parent_id: parentId,
        thread_id: threadId,
        action: 'send',
        images: [] as File[],
    });
    const [active, setActive] = useState<'send' | 'draft' | null>(null);

    // OpenPNE 3's two submit buttons (send / draft) as one form; transform stamps the chosen action.
    const submit = (action: 'send' | 'draft') => {
        setActive(action);
        form.transform((data) => ({ ...data, action }));
        form.post('/m/message/sendToFriend', { forceFormData: true, onFinish: () => setActive(null) });
    };

    const imageError = Object.entries(form.errors).find(([key]) => key.startsWith('images'))?.[1];
    const incomplete = form.data.subject.trim() === '' || form.data.body.trim() === '';

    return (
        <>
            <Head title={t('Compose Message')} />
            <main className="mx-auto max-w-2xl space-y-6 px-4 py-8">
                {flash.error && <FlashMessage variant="error">{flash.error}</FlashMessage>}

                <h1 className="text-xl font-semibold text-foreground">{t('Compose Message')}</h1>

                <form onSubmit={(e) => e.preventDefault()} className="space-y-4">
                    <div className="flex items-center gap-2 text-sm">
                        <span className="font-medium text-muted-foreground">{t('Recipient')}</span>
                        <Avatar id={recipient.id} name={recipient.name} src={recipient.imageUrl} size="sm" />
                        <Link href={`/m/member/${recipient.id}`} className="text-link hover:underline">
                            {recipient.name}
                        </Link>
                    </div>

                    <Field label={t('Subject')} htmlFor="message_subject" error={form.errors.subject}>
                        <Input
                            id="message_subject"
                            type="text"
                            required
                            value={form.data.subject}
                            onChange={(e) => form.setData('subject', e.target.value)}
                        />
                    </Field>

                    <Field label={t('Body')} htmlFor="message_body" error={form.errors.body}>
                        <Textarea
                            id="message_body"
                            required
                            rows={8}
                            value={form.data.body}
                            onChange={(e) => form.setData('body', e.target.value)}
                        />
                    </Field>

                    <Field label={t('Images')} htmlFor="message_images" error={imageError}>
                        <input
                            id="message_images"
                            type="file"
                            accept="image/jpeg,image/png,image/gif,image/webp"
                            multiple
                            // Send every selection; the server caps the count and surfaces an error
                            // (a silent client-side truncation would drop attachments without warning).
                            onChange={(e) => form.setData('images', Array.from(e.target.files ?? []))}
                            className="block w-full text-sm text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-secondary file:px-3 file:py-2 file:text-sm file:font-medium file:text-secondary-foreground hover:file:bg-secondary/80"
                        />
                    </Field>

                    <FormActions>
                        <Button onClick={() => submit('send')} loading={active === 'send'} disabled={form.processing || incomplete}>
                            {t('Send')}
                        </Button>
                        <Button
                            variant="secondary"
                            onClick={() => submit('draft')}
                            loading={active === 'draft'}
                            disabled={form.processing || incomplete}
                        >
                            {t('Save as draft')}
                        </Button>
                    </FormActions>
                </form>
            </main>
        </>
    );
}
