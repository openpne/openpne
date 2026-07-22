import { Head, useForm, usePage } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { BodyField } from '@/components/compose/body-field';
import { initialComposeFormat, type ComposeEditorPreference, type RecordFormat } from '@/components/compose/editor-mode';
import { CurrentImagesField } from '@/components/current-images-field';
import { ImagesField } from '@/components/images-field';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { CommunitySummary, TopicImage } from '../types';

interface EditEvent {
    id: number;
    name: string;
    body: string;
    format: string; // BodyFormat: 'plain' | 'op3' | 'markdown'
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
    composeEditor: ComposeEditorPreference;
}

export default function CommunityEventEdit() {
    const t = useT();
    const { community, event, composeEditor } = usePage<EditProps>().props;
    const isEdit = event !== null;
    // op3 is a migration-only format with no author-facing editor: initialComposeFormat returns
    // undefined so `format` is omitted from the form, and the update preserves the stored format.
    const recordFormat = event?.format as RecordFormat | undefined;
    const format = initialComposeFormat(composeEditor, recordFormat);

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
        ...(format === undefined ? {} : { format }),
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        // No forceFormData — a fileless save posts JSON, keeping LF byte-stable
        // (multipart normalizes LF to CRLF); Inertia auto-switches when a File is attached.
        form.post(isEdit ? `/communityEvent/update/${event.id}` : `/communityEvent/create/${community.id}`);
    };

    const toggleRemove = (imageId: number, remove: boolean) => {
        form.setData('remove_images', remove ? [...form.data.remove_images, imageId] : form.data.remove_images.filter((id) => id !== imageId));
    };

    const title = isEdit ? t('Edit event') : t('Create an event');

    return (
        <>
            <Head title={title} />
            <h1 className="break-words text-xl font-semibold text-foreground">{title}</h1>

            <Panel overflow="visible" bleed>
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

                    <BodyField
                        id="body"
                        label={t('Body')}
                        value={form.data.body}
                        onChange={(body) => form.setData('body', body)}
                        error={form.errors.body}
                        rows={8}
                        required
                        format={form.data.format}
                        onFormatChange={(format) => form.setData('format', format)}
                        editorPreference={composeEditor}
                        recordFormat={recordFormat}
                    />

                    <CurrentImagesField images={event?.images ?? []} removedIds={form.data.remove_images} onToggle={toggleRemove} />

                    <ImagesField id="images" label={t('Add images')} files={form.data.images} onChange={(files) => form.setData('images', files)} errors={form.errors} />

                    <Button
                        type="submit"
                        loading={form.processing}
                        disabled={form.data.name.trim() === '' || form.data.body.trim() === '' || form.data.area.trim() === ''}
                    >
                        {isEdit ? t('Save') : t('Post')}
                    </Button>
                </form>
            </Panel>
        </>
    );
}
