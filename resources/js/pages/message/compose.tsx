import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { COMPOSE_FORM_ID, ComposeSheetAction } from '@/components/compose/compose-sheet-action';
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
        form.post('/message/sendToFriend', { forceFormData: true, onFinish: () => setActive(null) });
    };

    const incomplete = form.data.subject.trim() === '' || form.data.body.trim() === '';

    return (
        <>
            <Head title={t('Compose Message')} />

            {/* Both actions ride the sheet header below lg — a draft button left behind at the foot of
                the form would read as belonging to something else. They are the same buttons as the
                pair below, deliberately not external submitters: this form has no native submit path
                (the pair posts from onClick), and a submit button before the form in tree order would
                become its default button and make Enter in the subject field send or save. */}
            <ComposeSheetAction>
                <Button
                    variant="secondary"
                    size="sm"
                    onClick={() => submit('draft')}
                    loading={active === 'draft'}
                    disabled={form.processing || incomplete}
                >
                    {t('Save as draft')}
                </Button>
                <Button size="sm" onClick={() => submit('send')} loading={active === 'send'} disabled={form.processing || incomplete}>
                    {t('Send')}
                </Button>
            </ComposeSheetAction>

            <h1 className="break-words text-lg font-semibold text-foreground lg:text-xl">{t('Compose Message')}</h1>

            <Panel sheet>
                <form id={COMPOSE_FORM_ID} onSubmit={(e) => e.preventDefault()} className="space-y-4">
                    <div className="flex items-center gap-2 text-sm">
                        <span className="font-medium text-muted-foreground">{t('Recipient')}</span>
                        <Avatar id={recipient.id} name={recipient.name} src={recipient.imageUrl} color={recipient.avatarColor} size="sm" decorative />
                        <Link href={`/member/${recipient.id}`} className="text-link hover:underline">
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

                    <FormActions className="max-lg:hidden">
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
