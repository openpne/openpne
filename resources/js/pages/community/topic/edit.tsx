import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { CommunitySummary, TopicDetail } from '../types';

interface EditProps extends PageProps {
    community: CommunitySummary;
    topic: TopicDetail | null; // null = create mode
}

export default function CommunityTopicEdit() {
    const t = useT();
    const { community, topic } = usePage<EditProps>().props;
    const isEdit = topic !== null;

    const form = useForm({
        name: topic?.name ?? '',
        body: topic?.body ?? '',
        images: [] as File[],
        remove_images: [] as number[],
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(isEdit ? `/m/community/topic/${topic.id}/edit` : `/m/community/${community.id}/topic`, {
            forceFormData: true,
        });
    };

    const toggleRemove = (imageId: number, remove: boolean) => {
        form.setData('remove_images', remove ? [...form.data.remove_images, imageId] : form.data.remove_images.filter((id) => id !== imageId));
    };

    const title = isEdit ? t('Edit %topic%') : t('Post a new %topic%');
    const backHref = isEdit ? `/m/community/topic/${topic.id}` : `/m/community/${community.id}/topic`;

    return (
        <>
            <Head title={title} />
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8">
                <p className="text-sm">
                    <Link href={backHref} className="text-muted-foreground hover:underline">
                        {community.name}
                    </Link>
                </p>
                <h1 className="text-2xl font-semibold">{title}</h1>

                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-1">
                        <label htmlFor="name">{t('Title')}</label>
                        <input
                            id="name"
                            type="text"
                            required
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            className="w-full rounded border px-2 py-1"
                        />
                        {form.errors.name && <p role="alert">{form.errors.name}</p>}
                    </div>

                    <div className="space-y-1">
                        <label htmlFor="body">{t('Body')}</label>
                        <textarea
                            id="body"
                            required
                            rows={10}
                            value={form.data.body}
                            onChange={(e) => form.setData('body', e.target.value)}
                            className="w-full rounded border px-2 py-1"
                        />
                        {form.errors.body && <p role="alert">{form.errors.body}</p>}
                    </div>

                    {isEdit && topic.images.length > 0 && (
                        <fieldset className="space-y-2">
                            <legend>{t('Current images')}</legend>
                            <ul className="flex flex-wrap gap-3">
                                {topic.images.map((image) => (
                                    <li key={image.id} className="space-y-1 text-center">
                                        <img src={image.thumbnailUrl} alt="" className="size-24 rounded object-cover" />
                                        <label className="flex items-center justify-center gap-1 text-sm">
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
                        <label htmlFor="images">{t('Add images')}</label>
                        <input
                            id="images"
                            type="file"
                            accept="image/jpeg,image/png,image/gif,image/webp"
                            multiple
                            onChange={(e) => form.setData('images', Array.from(e.target.files ?? []).slice(0, 3))}
                        />
                        {form.errors.images && <p role="alert">{form.errors.images}</p>}
                    </div>

                    <button
                        type="submit"
                        disabled={form.processing || form.data.name.trim() === '' || form.data.body.trim() === ''}
                        className="min-h-11 rounded-full bg-blue-600 px-5 text-sm font-medium text-white transition hover:bg-blue-700 disabled:opacity-50"
                    >
                        {isEdit ? t('Save') : t('Post')}
                    </button>
                </form>
            </main>
        </>
    );
}
