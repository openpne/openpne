import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { COMPOSE_FORM_ID, ComposeSheetAction } from '@/components/compose/compose-sheet-action';
import { CurrentImagesField } from '@/components/current-images-field';
import { ImagesField } from '@/components/images-field';
import { Avatar } from '@/components/avatar';
import { Button } from '@/components/ui/button';
import { Field, FormActions } from '@/components/ui/field';
import { Heading } from '@/components/ui/heading';
import { Input } from '@/components/ui/input';
import { Panel } from '@/components/ui/surface';
import { Textarea } from '@/components/ui/textarea';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { MessageDraftForm } from './types';

interface EditProps extends PageProps {
    draft: MessageDraftForm;
}

export default function MessageEdit() {
    const t = useT();
    const { draft } = usePage<EditProps>().props;

    const form = useForm({
        subject: draft.subject,
        body: draft.body,
        remove_images: [] as number[],
        images: [] as File[],
        action: 'send',
    });
    const [active, setActive] = useState<'send' | 'draft' | null>(null);

    // Two submit buttons (send / draft) as one form; transform stamps the chosen action.
    const submit = (action: 'send' | 'draft') => {
        setActive(action);
        form.transform((data) => ({ ...data, action }));
        form.post(`/message/edit/${draft.id}`, { forceFormData: true, onFinish: () => setActive(null) });
    };

    const toggleRemove = (id: number, checked: boolean) => {
        form.setData('remove_images', checked ? [...form.data.remove_images, id] : form.data.remove_images.filter((x) => x !== id));
    };

    const incomplete = form.data.subject.trim() === '' || form.data.body.trim() === '';

    return (
        <>
            <Head title={t('Edit draft')} />

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

            <Heading variant="pageCompose">{t('Edit draft')}</Heading>

            <Panel sheet>
                <form id={COMPOSE_FORM_ID} onSubmit={(e) => e.preventDefault()} className="space-y-4">
                    {draft.recipient && (
                        <div className="flex items-center gap-2 text-sm">
                            <span className="font-medium text-muted-foreground">{t('Recipient')}</span>
                            <Avatar id={draft.recipient.id} name={draft.recipient.name} src={draft.recipient.imageUrl} color={draft.recipient.avatarColor} size="sm" decorative />
                            <Link href={`/member/${draft.recipient.id}`} className="text-link hover:underline">
                                {draft.recipient.name}
                            </Link>
                        </div>
                    )}

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

                    <CurrentImagesField images={draft.images} removedIds={form.data.remove_images} onToggle={toggleRemove} />

                    <ImagesField id="message_images" label={t('Add images')} files={form.data.images} onChange={(files) => form.setData('images', files)} errors={form.errors} />

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
