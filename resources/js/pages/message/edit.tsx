import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Avatar } from '@/components/avatar';
import { FlashMessage } from '@/components/flash-message';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FormActions } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { MessageDraftForm } from './types';

interface EditProps extends PageProps {
    draft: MessageDraftForm;
}

export default function MessageEdit() {
    const t = useT();
    const { draft, flash } = usePage<EditProps>().props;

    const form = useForm({
        subject: draft.subject,
        body: draft.body,
        remove_images: [] as number[],
        images: [] as File[],
        action: 'send',
    });
    const [active, setActive] = useState<'send' | 'draft' | null>(null);

    // OpenPNE 3's two submit buttons (send / draft) as one form; transform stamps the chosen action.
    const submit = (action: 'send' | 'draft') => {
        setActive(action);
        form.transform((data) => ({ ...data, action }));
        form.post(`/m/message/edit/${draft.id}`, { forceFormData: true, onFinish: () => setActive(null) });
    };

    const toggleRemove = (id: number, checked: boolean) => {
        form.setData('remove_images', checked ? [...form.data.remove_images, id] : form.data.remove_images.filter((x) => x !== id));
    };

    const imageError = Object.entries(form.errors).find(([key]) => key.startsWith('images'))?.[1];
    const incomplete = form.data.subject.trim() === '' || form.data.body.trim() === '';

    return (
        <>
            <Head title={t('Edit draft')} />
            <main className="mx-auto max-w-2xl space-y-6 px-4 py-8">
                {flash.error && <FlashMessage variant="error">{flash.error}</FlashMessage>}

                <h1 className="text-xl font-semibold text-foreground">{t('Edit draft')}</h1>

                <form onSubmit={(e) => e.preventDefault()} className="space-y-4">
                    {draft.recipient && (
                        <div className="flex items-center gap-2 text-sm">
                            <span className="font-medium text-muted-foreground">{t('Recipient')}</span>
                            <Avatar id={draft.recipient.id} name={draft.recipient.name} src={draft.recipient.imageUrl} size="sm" />
                            <Link href={`/m/member/${draft.recipient.id}`} className="text-link hover:underline">
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

                    {draft.images.length > 0 && (
                        <fieldset className="space-y-2">
                            <legend className="text-sm font-medium text-foreground">{t('Current images')}</legend>
                            <ul className="flex flex-wrap gap-3">
                                {draft.images.map((image) => (
                                    <li key={image.id} className="space-y-1 text-center">
                                        <img src={image.thumbnailUrl} alt="" className="size-24 rounded-md object-cover" />
                                        <label className="flex items-center justify-center gap-1 text-xs text-foreground">
                                            <Checkbox
                                                checked={form.data.remove_images.includes(image.id)}
                                                onChange={(e) => toggleRemove(image.id, e.target.checked)}
                                            />
                                            {t('Delete')}
                                        </label>
                                    </li>
                                ))}
                            </ul>
                        </fieldset>
                    )}

                    <Field label={t('Add images')} htmlFor="message_images" error={imageError}>
                        <input
                            id="message_images"
                            type="file"
                            accept="image/jpeg,image/png,image/gif,image/webp"
                            multiple
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
