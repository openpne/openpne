import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { CommunitySummary, TopicImage } from '../types';

interface EditEvent {
    id: number;
    name: string;
    body: string;
    openDate: string; // Y-m-d
    openDateComment: string;
    area: string;
    applicationDeadline: string | null; // Y-m-d
    capacity: number | null;
    images: TopicImage[];
}

interface EditProps extends PageProps {
    community: CommunitySummary;
    event: EditEvent | null; // null = create mode
}

export default function CommunityEventEdit() {
    const t = useT();
    const { community, event } = usePage<EditProps>().props;
    const isEdit = event !== null;

    const form = useForm({
        name: event?.name ?? '',
        body: event?.body ?? '',
        open_date: event?.openDate ?? '',
        open_date_comment: event?.openDateComment ?? '',
        area: event?.area ?? '',
        application_deadline: event?.applicationDeadline ?? '',
        capacity: event?.capacity != null ? String(event.capacity) : '',
        images: [] as File[],
        remove_images: [] as number[],
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(isEdit ? `/m/community/event/${event.id}/edit` : `/m/community/${community.id}/event`, {
            forceFormData: true,
        });
    };

    const toggleRemove = (imageId: number, remove: boolean) => {
        form.setData('remove_images', remove ? [...form.data.remove_images, imageId] : form.data.remove_images.filter((id) => id !== imageId));
    };

    const title = isEdit ? t('Edit event') : t('Post a new event');
    const backHref = isEdit ? `/m/community/event/${event.id}` : `/m/community/${community.id}/event`;

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
                        <label htmlFor="open_date">{t('Open date')}</label>
                        <input
                            id="open_date"
                            type="date"
                            required
                            value={form.data.open_date}
                            onChange={(e) => form.setData('open_date', e.target.value)}
                            className="block rounded border px-2 py-1"
                        />
                        {form.errors.open_date && <p role="alert">{form.errors.open_date}</p>}
                    </div>

                    <div className="space-y-1">
                        <label htmlFor="open_date_comment">{t('Note')}</label>
                        <input
                            id="open_date_comment"
                            type="text"
                            value={form.data.open_date_comment}
                            onChange={(e) => form.setData('open_date_comment', e.target.value)}
                            className="w-full rounded border px-2 py-1"
                        />
                    </div>

                    <div className="space-y-1">
                        <label htmlFor="area">{t('Area')}</label>
                        <input
                            id="area"
                            type="text"
                            required
                            value={form.data.area}
                            onChange={(e) => form.setData('area', e.target.value)}
                            className="w-full rounded border px-2 py-1"
                        />
                        {form.errors.area && <p role="alert">{form.errors.area}</p>}
                    </div>

                    <div className="space-y-1">
                        <label htmlFor="application_deadline">{t('Application deadline')}</label>
                        <input
                            id="application_deadline"
                            type="date"
                            value={form.data.application_deadline}
                            onChange={(e) => form.setData('application_deadline', e.target.value)}
                            className="block rounded border px-2 py-1"
                        />
                        {form.errors.application_deadline && <p role="alert">{form.errors.application_deadline}</p>}
                    </div>

                    <div className="space-y-1">
                        <label htmlFor="capacity">{t('Capacity')}</label>
                        <input
                            id="capacity"
                            type="number"
                            min={0}
                            value={form.data.capacity}
                            onChange={(e) => form.setData('capacity', e.target.value)}
                            className="block w-32 rounded border px-2 py-1"
                        />
                        {form.errors.capacity && <p role="alert">{form.errors.capacity}</p>}
                    </div>

                    <div className="space-y-1">
                        <label htmlFor="body">{t('Body')}</label>
                        <textarea
                            id="body"
                            required
                            rows={8}
                            value={form.data.body}
                            onChange={(e) => form.setData('body', e.target.value)}
                            className="w-full rounded border px-2 py-1"
                        />
                        {form.errors.body && <p role="alert">{form.errors.body}</p>}
                    </div>

                    {isEdit && event.images.length > 0 && (
                        <fieldset className="space-y-2">
                            <legend>{t('Current images')}</legend>
                            <ul className="flex flex-wrap gap-3">
                                {event.images.map((image) => (
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
                        disabled={form.processing}
                        className="min-h-11 rounded-full bg-blue-600 px-5 text-sm font-medium text-white transition hover:bg-blue-700 disabled:opacity-50"
                    >
                        {isEdit ? t('Save') : t('Post')}
                    </button>
                </form>
            </main>
        </>
    );
}
