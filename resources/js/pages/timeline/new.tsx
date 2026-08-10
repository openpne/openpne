import { Head, useForm } from '@inertiajs/react';
import { COMPOSE_FORM_ID, ComposeSheetAction } from '@/components/compose/compose-sheet-action';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Heading } from '@/components/ui/heading';
import { Select } from '@/components/ui/select';
import { Panel } from '@/components/ui/surface';
import { Textarea } from '@/components/ui/textarea';
import { useT } from '@/lib/i18n';

type VisibilityOption = { value: string; label: string };

export default function TimelineNew({
    defaultVisibility,
    visibilityOptions,
}: {
    defaultVisibility: string;
    visibilityOptions: VisibilityOption[];
}) {
    const t = useT();
    const { data, setData, post, errors, processing } = useForm({
        body: '',
        visibility: defaultVisibility,
        image: null as File | null,
    });

    return (
        <>
            <Head title={t('%Post_activity%')} />
            <ComposeSheetAction>
                <Button type="submit" form={COMPOSE_FORM_ID} size="sm" loading={processing}>
                    {t('%Post_activity%')}
                </Button>
            </ComposeSheetAction>
            <Heading variant="pageCompose">{t('%Post_activity%')}</Heading>

            <Panel sheet>
                <form
                    id={COMPOSE_FORM_ID}
                    onSubmit={(e) => {
                        e.preventDefault();
                        // forceFormData: the upload needs a multipart body, which Inertia uses
                        // automatically once a File is present but not for an initially-null field.
                        post('/timeline/create', { forceFormData: true });
                    }}
                    className="space-y-4"
                >
                    <Field label={t('Body')} htmlFor="timeline_body" error={errors.body}>
                        <Textarea id="timeline_body" required maxLength={140} rows={4} value={data.body} onChange={(e) => setData('body', e.target.value)} />
                    </Field>

                    <Field label={t('Visibility')} htmlFor="timeline_visibility" error={errors.visibility}>
                        <Select id="timeline_visibility" value={data.visibility} onChange={(e) => setData('visibility', e.target.value)}>
                            {visibilityOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {t(option.label)}
                                </option>
                            ))}
                        </Select>
                    </Field>

                    <Field label={t('Image')} htmlFor="timeline_image" error={errors.image}>
                        <input
                            id="timeline_image"
                            type="file"
                            accept="image/jpeg,image/png,image/gif,image/webp"
                            onChange={(e) => setData('image', e.target.files?.[0] ?? null)}
                            className="block w-full text-sm text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-secondary file:px-3 file:py-2 file:text-sm file:text-secondary-foreground hover:file:bg-secondary/80"
                        />
                    </Field>

                    {/* The sheet header carries this action below lg (ComposeSheetAction above). */}
                    <Button type="submit" className="max-lg:hidden" loading={processing}>
                        {t('%Post_activity%')}
                    </Button>
                </form>
            </Panel>
        </>
    );
}
