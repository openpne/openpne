import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
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

    const submit = (e: FormEvent) => {
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
                    <Link href={backHref} className="text-muted-foreground hover:text-foreground hover:underline">
                        {community.name}
                    </Link>
                </p>
                <h1 className="text-xl font-semibold text-foreground">{title}</h1>

                <form onSubmit={submit} className="space-y-4">
                    <Field label={t('Title')} htmlFor="name" error={form.errors.name}>
                        <Input id="name" type="text" required value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                    </Field>

                    <Field label={t('Open date')} htmlFor="open_date" error={form.errors.open_date}>
                        <Input id="open_date" type="date" required value={form.data.open_date} onChange={(e) => form.setData('open_date', e.target.value)} />
                    </Field>

                    <Field label={t('Note')} htmlFor="open_date_comment">
                        <Input id="open_date_comment" type="text" value={form.data.open_date_comment} onChange={(e) => form.setData('open_date_comment', e.target.value)} />
                    </Field>

                    <Field label={t('Area')} htmlFor="area" error={form.errors.area}>
                        <Input id="area" type="text" required value={form.data.area} onChange={(e) => form.setData('area', e.target.value)} />
                    </Field>

                    <Field label={t('Application deadline')} htmlFor="application_deadline" error={form.errors.application_deadline}>
                        <Input id="application_deadline" type="date" value={form.data.application_deadline} onChange={(e) => form.setData('application_deadline', e.target.value)} />
                    </Field>

                    <Field label={t('Capacity')} htmlFor="capacity" error={form.errors.capacity}>
                        <Input id="capacity" type="number" min={0} className="w-32" value={form.data.capacity} onChange={(e) => form.setData('capacity', e.target.value)} />
                    </Field>

                    <Field label={t('Body')} htmlFor="body" error={form.errors.body}>
                        <Textarea id="body" required rows={8} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} />
                    </Field>

                    {isEdit && event.images.length > 0 && (
                        <fieldset className="space-y-2">
                            <legend className="text-sm font-medium text-foreground">{t('Current images')}</legend>
                            <ul className="flex flex-wrap gap-3">
                                {event.images.map((image, i) => (
                                    <li key={image.id} className="space-y-1 text-center">
                                        <img src={image.thumbnailUrl} alt="" className="size-24 rounded-md object-cover" />
                                        <label className="flex items-center justify-center gap-1 text-sm text-foreground">
                                            <Checkbox
                                                aria-label={`${t('Delete')} ${t('Image')} ${i + 1}`}
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

                    <Field label={t('Add images')} htmlFor="images" error={form.errors.images}>
                        <input
                            id="images"
                            type="file"
                            accept="image/jpeg,image/png,image/gif,image/webp"
                            multiple
                            onChange={(e) => form.setData('images', Array.from(e.target.files ?? []).slice(0, 3))}
                            className="block w-full text-sm text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-secondary file:px-3 file:py-2 file:text-sm file:font-medium file:text-secondary-foreground hover:file:bg-secondary/80"
                        />
                    </Field>

                    <Button type="submit" loading={form.processing}>
                        {isEdit ? t('Save') : t('Post')}
                    </Button>
                </form>
            </main>
        </>
    );
}
