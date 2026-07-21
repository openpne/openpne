import { Head, useForm } from '@inertiajs/react';
import { BodyField } from '@/components/compose/body-field';
import { initialComposeFormat } from '@/components/compose/editor-mode';
import { ImagesField } from '@/components/images-field';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';

type VisibilityOption = { value: string; label: string };

export default function DiaryNew({
    defaultVisibility,
    visibilityOptions,
    composeEditor,
}: {
    defaultVisibility: string;
    visibilityOptions: VisibilityOption[];
    composeEditor: 'rich' | 'markdown';
}) {
    const t = useT();
    const format = initialComposeFormat(composeEditor, undefined);
    const { data, setData, post, errors, processing } = useForm({
        title: '',
        body: '',
        visibility: defaultVisibility,
        images: [] as File[],
        ...(format === undefined ? {} : { format }),
    });

    return (
        <>
            <Head title={t('Write a %diary%')} />
            <h1 className="break-words text-xl font-semibold text-foreground">{t('Write a %diary%')}</h1>

            <Panel>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        // No forceFormData: Inertia switches to multipart only when a File is
                        // attached. A fileless save posts JSON, keeping the body's LF line endings
                        // byte-stable (multipart encoding normalizes LF to CRLF — body-text.md).
                        post('/diary/create');
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
                    />

                    <Field label={t('Visibility')} htmlFor="diary_visibility" error={errors.visibility}>
                        <Select id="diary_visibility" value={data.visibility} onChange={(e) => setData('visibility', e.target.value)}>
                            {visibilityOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {t(option.label)}
                                </option>
                            ))}
                        </Select>
                    </Field>

                    <ImagesField id="diary_images" label={t('Images')} files={data.images} onChange={(files) => setData('images', files)} errors={errors} />

                    <Button type="submit" loading={processing} disabled={data.body.trim() === ''}>
                        {t('Post')}
                    </Button>
                </form>
            </Panel>
        </>
    );
}
