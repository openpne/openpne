import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { ImagesField } from '@/components/images-field';
import { Avatar } from '@/components/avatar';
import { Button } from '@/components/ui/button';
import { Field, FormActions } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Panel } from '@/components/ui/surface';
import { Textarea } from '@/components/ui/textarea';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { MessageMember } from './types';

interface ComposeProps extends PageProps {
    recipient: MessageMember;
    parentId: number | null; // reply links
    threadId: number | null;
    subject: string; // prefilled on reply ("Re: …")
    body: string; // prefilled on reply (the original quoted)
}

export default function MessageCompose() {
    const t = useT();
    const { recipient, parentId, threadId, subject, body } = usePage<ComposeProps>().props;

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

    // Two submit buttons (send / draft) as one form; transform stamps the chosen action.
    const submit = (action: 'send' | 'draft') => {
        setActive(action);
        form.transform((data) => ({ ...data, action }));
        form.post('/m/message/sendToFriend', { forceFormData: true, onFinish: () => setActive(null) });
    };

    const incomplete = form.data.subject.trim() === '' || form.data.body.trim() === '';

    return (
        <>
            <Head title={t('Compose Message')} />

            <h1 className="break-words text-xl font-semibold text-foreground">{t('Compose Message')}</h1>

            <Panel>
                <form onSubmit={(e) => e.preventDefault()} className="space-y-4">
                    <div className="flex items-center gap-2 text-sm">
                        <span className="font-medium text-muted-foreground">{t('Recipient')}</span>
                        <Avatar id={recipient.id} name={recipient.name} src={recipient.imageUrl} color={recipient.avatarColor} size="sm" decorative />
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

                    <ImagesField id="message_images" label={t('Images')} files={form.data.images} onChange={(files) => form.setData('images', files)} errors={form.errors} />

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
            </Panel>
        </>
    );
}
