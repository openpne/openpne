import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Avatar } from '@/components/avatar';
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

    // OpenPNE 3's two submit buttons (send / draft) as one form; transform stamps the chosen action.
    const submit = (action: 'send' | 'draft') => {
        form.transform((data) => ({ ...data, action }));
        form.post('/m/message/sendToFriend', { forceFormData: true });
    };

    const imageError = Object.entries(form.errors).find(([key]) => key.startsWith('images'))?.[1];

    return (
        <>
            <Head title={t('Compose Message')} />
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8">
                {flash.error && <p role="alert">{flash.error}</p>}

                <h1 className="text-2xl font-semibold">{t('Compose Message')}</h1>

                <form onSubmit={(e) => e.preventDefault()} className="space-y-4">
                    <div className="flex items-center gap-2 text-sm">
                        <span className="font-medium text-muted-foreground">{t('Recipient')}</span>
                        <Avatar id={recipient.id} name={recipient.name} src={recipient.imageUrl} size="sm" />
                        <Link href={`/m/member/${recipient.id}`} className="hover:underline">
                            {recipient.name}
                        </Link>
                    </div>

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

                    <div className="space-y-1">
                        <label htmlFor="message_images" className="block text-sm font-medium">
                            {t('Images')}
                        </label>
                        <input
                            id="message_images"
                            type="file"
                            accept="image/jpeg,image/png,image/gif,image/webp"
                            multiple
                            // Send every selection; the server caps the count and surfaces an error
                            // (a silent client-side truncation would drop attachments without warning).
                            onChange={(e) => form.setData('images', Array.from(e.target.files ?? []))}
                        />
                        {imageError && <p role="alert">{imageError}</p>}
                    </div>

                    <div className="flex gap-3">
                        <button
                            type="button"
                            onClick={() => submit('send')}
                            disabled={form.processing || form.data.subject.trim() === '' || form.data.body.trim() === ''}
                            className="min-h-11 rounded-full bg-blue-600 px-5 text-sm font-medium text-white transition hover:bg-blue-700 disabled:opacity-50"
                        >
                            {t('Send')}
                        </button>
                        <button
                            type="button"
                            onClick={() => submit('draft')}
                            disabled={form.processing || form.data.subject.trim() === '' || form.data.body.trim() === ''}
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
