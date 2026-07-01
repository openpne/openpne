import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Avatar } from '@/components/avatar';
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

    // OpenPNE 3's two submit buttons (send / draft) as one form; transform stamps the chosen action.
    const submit = (action: 'send' | 'draft') => {
        form.transform((data) => ({ ...data, action }));
        form.post(`/m/message/edit/${draft.id}`, { forceFormData: true });
    };

    const toggleRemove = (id: number, checked: boolean) => {
        form.setData('remove_images', checked ? [...form.data.remove_images, id] : form.data.remove_images.filter((x) => x !== id));
    };

    const imageError = Object.entries(form.errors).find(([key]) => key.startsWith('images'))?.[1];
    const disabled = form.processing || form.data.subject.trim() === '' || form.data.body.trim() === '';

    return (
        <>
            <Head title={t('Edit draft')} />
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8">
                {flash.error && <p role="alert">{flash.error}</p>}

                <h1 className="text-2xl font-semibold">{t('Edit draft')}</h1>

                <form onSubmit={(e) => e.preventDefault()} className="space-y-4">
                    {draft.recipient && (
                        <div className="flex items-center gap-2 text-sm">
                            <span className="font-medium text-muted-foreground">{t('Recipient')}</span>
                            <Avatar id={draft.recipient.id} name={draft.recipient.name} src={draft.recipient.imageUrl} size="sm" />
                            <Link href={`/m/member/${draft.recipient.id}`} className="hover:underline">
                                {draft.recipient.name}
                            </Link>
                        </div>
                    )}

                    <div className="space-y-1">
                        <label htmlFor="message_subject" className="block text-sm font-medium">
                            {t('Subject')}
                        </label>
                        <input
                            id="message_subject"
                            type="text"
                            value={form.data.subject}
                            onChange={(e) => form.setData('subject', e.target.value)}
                            required
                            className="w-full rounded border px-2 py-1"
                        />
                        {form.errors.subject && <p role="alert">{form.errors.subject}</p>}
                    </div>

                    <div className="space-y-1">
                        <label htmlFor="message_body" className="block text-sm font-medium">
                            {t('Body')}
                        </label>
                        <textarea
                            id="message_body"
                            value={form.data.body}
                            onChange={(e) => form.setData('body', e.target.value)}
                            required
                            rows={8}
                            className="w-full rounded border px-2 py-1"
                        />
                        {form.errors.body && <p role="alert">{form.errors.body}</p>}
                    </div>

                    {draft.images.length > 0 && (
                        <fieldset className="space-y-1">
                            <legend className="text-sm font-medium">{t('Current images')}</legend>
                            <ul className="flex flex-wrap gap-3">
                                {draft.images.map((image) => (
                                    <li key={image.id} className="space-y-1 text-center">
                                        <img src={image.thumbnailUrl} alt="" className="size-24 rounded object-cover" />
                                        <label className="flex items-center justify-center gap-1 text-xs">
                                            <input
                                                type="checkbox"
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

                    <div className="space-y-1">
                        <label htmlFor="message_images" className="block text-sm font-medium">
                            {t('Add images')}
                        </label>
                        <input
                            id="message_images"
                            type="file"
                            accept="image/jpeg,image/png,image/gif,image/webp"
                            multiple
                            onChange={(e) => form.setData('images', Array.from(e.target.files ?? []))}
                        />
                        {imageError && <p role="alert">{imageError}</p>}
                    </div>

                    <div className="flex gap-3">
                        <button
                            type="button"
                            onClick={() => submit('send')}
                            disabled={disabled}
                            className="min-h-11 rounded-full bg-blue-600 px-5 text-sm font-medium text-white transition hover:bg-blue-700 disabled:opacity-50"
                        >
                            {t('Send')}
                        </button>
                        <button
                            type="button"
                            onClick={() => submit('draft')}
                            disabled={disabled}
                            className="min-h-11 rounded-full bg-slate-100 px-5 text-sm font-medium text-slate-700 transition hover:bg-slate-200 disabled:opacity-50 dark:bg-slate-700 dark:text-slate-200 dark:hover:bg-slate-600"
                        >
                            {t('Save as draft')}
                        </button>
                    </div>
                </form>
            </main>
        </>
    );
}
