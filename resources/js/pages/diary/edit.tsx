import { Head, useForm, usePage } from '@inertiajs/react';
import { BodyField } from '@/components/compose/body-field';
import { initialComposeFormat, type ComposeEditorPreference, type RecordFormat } from '@/components/compose/editor-mode';
import { CurrentImagesField } from '@/components/current-images-field';
import { ImagesField } from '@/components/images-field';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { DiaryDetail } from './types';

interface EditProps extends PageProps {
    diary: DiaryDetail;
    composeEditor: ComposeEditorPreference;
}

export default function DiaryEdit() {
    const t = useT();
    const { diary, composeEditor } = usePage<EditProps>().props;
    // op3 is a migration-only format with no author-facing editor: initialComposeFormat returns
    // undefined so `format` is omitted from the form, and the update preserves the stored format.
    const recordFormat = diary.format as RecordFormat;
    const format = initialComposeFormat(composeEditor, recordFormat);
    const { data, setData, post, errors, processing } = useForm({
        title: diary.title,
        body: diary.body,
        visibility: String(
            diary.visibility === 'private' ? 3 : diary.visibility === 'friends' ? 2 : 1,
        ),
        images: [] as File[],
        remove_images: [] as number[],
        ...(format === undefined ? {} : { format }),
    });

    const toggleRemove = (id: number, remove: boolean) =>
        setData(
            'remove_images',
            remove ? [...data.remove_images, id] : data.remove_images.filter((x) => x !== id),
        );

    return (
        <>
            <Head title={t('Edit %diary%')} />
            <h1 className="break-words text-xl font-semibold text-foreground">{t('Edit %diary%')}</h1>

            <Panel overflow="visible">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        // No forceFormData — a fileless save posts JSON, keeping LF byte-stable
                        // (multipart normalizes LF to CRLF); Inertia auto-switches when a File is attached.
                        post(`/diary/update/${diary.id}`);
                    }}
                    className="space-y-4"
                >
                    <Field label={t('Title')} htmlFor="diary_title" error={errors.title}>
                        <Input id="diary_title" type="text" required value={data.title} onChange={(e) => setData('title', e.target.value)} />
                    </Field>

                    <BodyField
                        id="diary_body"
                        label={t('Body')}
                        value={data.body}
                        onChange={(body) => setData('body', body)}
                        error={errors.body}
                        rows={10}
                        required
                        format={data.format}
                        onFormatChange={(format) => setData('format', format)}
                        editorPreference={composeEditor}
                        recordFormat={recordFormat}
                    />

                    <Field label={t('Visibility')} htmlFor="diary_visibility" error={errors.visibility}>
                        <Select id="diary_visibility" value={data.visibility} onChange={(e) => setData('visibility', e.target.value)}>
                            <option value="1">{t('All members')}</option>
                            <option value="2">{t('%Friends% only')}</option>
                            <option value="3">{t('Private')}</option>
                        </Select>
                    </Field>

                    <CurrentImagesField images={diary.images} removedIds={data.remove_images} onToggle={toggleRemove} />

                    <ImagesField id="diary_images" label={t('Images')} files={data.images} onChange={(files) => setData('images', files)} errors={errors} />

                    <Button type="submit" loading={processing} disabled={data.body.trim() === ''}>
                        {t('Save')}
                    </Button>
                </form>
            </Panel>
        </>
    );
}
