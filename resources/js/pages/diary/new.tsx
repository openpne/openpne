import { Head, useForm } from '@inertiajs/react';
import { BodyField } from '@/components/compose/body-field';
import { initialComposeFormat, type ComposeEditorPreference } from '@/components/compose/editor-mode';
import { ImagesField } from '@/components/images-field';
import { Button } from '@/components/ui/button';
import { FormActions, FormRow } from '@/components/ui/field';
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
    composeEditor: ComposeEditorPreference;
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

            <Panel overflow="visible" bleed="full">
                {/* Below sm the form is a stack of hairline-separated full-width rows (each row owns
                    --frame-inset); from sm up the dividers go and the usual 4-gap stack returns. */}
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        // No forceFormData: Inertia switches to multipart only when a File is
                        // attached. A fileless save posts JSON, keeping the body's LF line endings
                        // byte-stable (multipart encoding normalizes LF to CRLF — body-text.md).
                        post('/diary/create');
                    }}
                    className="divide-y divide-border sm:space-y-4 sm:divide-y-0"
                >
                    <FormRow label={t('Title')} htmlFor="diary_title" error={errors.title}>
                        <Input
                            id="diary_title"
                            type="text"
                            variant="bare"
                            required
                            value={data.title}
                            onChange={(e) => setData('title', e.target.value)}
                        />
                    </FormRow>

                    <BodyField
                        id="diary_body"
                        label={t('Body')}
                        layout="row"
                        value={data.body}
                        onChange={(body) => setData('body', body)}
                        error={errors.body}
                        rows={10}
                        required
                        format={data.format}
                        onFormatChange={(format) => setData('format', format)}
                        editorPreference={composeEditor}
                    />

                    <FormRow label={t('Visibility')} htmlFor="diary_visibility" error={errors.visibility}>
                        <Select
                            id="diary_visibility"
                            variant="bare"
                            value={data.visibility}
                            onChange={(e) => setData('visibility', e.target.value)}
                        >
                            {visibilityOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {t(option.label)}
                                </option>
                            ))}
                        </Select>
                    </FormRow>

                    <ImagesField
                        id="diary_images"
                        label={t('Images')}
                        layout="row"
                        files={data.images}
                        onChange={(files) => setData('images', files)}
                        errors={errors}
                    />

                    <FormActions row>
                        <Button type="submit" loading={processing} disabled={data.body.trim() === ''}>
                            {t('Post')}
                        </Button>
                    </FormActions>
                </form>
            </Panel>
        </>
    );
}
