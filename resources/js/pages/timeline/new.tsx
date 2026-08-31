import { Head, useForm } from '@inertiajs/react';
import { useId } from 'react';
import { COMPOSE_FORM_ID, ComposeSheetAction } from '@/components/compose/compose-sheet-action';
import { MentionTextarea } from '@/components/compose/mention-textarea';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Heading } from '@/components/ui/heading';
import { Select } from '@/components/ui/select';
import { Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import { toPayload, type DraftMention } from '@/lib/mention-draft';
import { BodyCounter, overBodyLimit } from './body-counter';

type VisibilityOption = { value: string; label: string };

export default function TimelineNew({
    defaultVisibility,
    visibilityOptions,
}: {
    defaultVisibility: string;
    visibilityOptions: VisibilityOption[];
}) {
    const t = useT();
    const counterId = useId();
    const { data, setData, post, errors, processing, transform } = useForm({
        body: '',
        visibility: defaultVisibility,
        image: null as File | null,
        mentions: [] as DraftMention[],
    });
    const tooLong = overBodyLimit(data.body);

    return (
        <>
            <Head title={t('%Post_activity%')} />
            <ComposeSheetAction>
                <Button type="submit" form={COMPOSE_FORM_ID} size="sm" loading={processing} disabled={tooLong}>
                    {t('%Post_activity%')}
                </Button>
            </ComposeSheetAction>
            <Heading variant="pageCompose">{t('%Post_activity%')}</Heading>

            {/* The mention popup hangs out of the body field's row. */}
            <Panel overflow="visible" chrome="sheet">
                <form
                    id={COMPOSE_FORM_ID}
                    onSubmit={(e) => {
                        e.preventDefault();
                        // The draft tracks a mention by where it sits in the DOM value; the request
                        // carries the code-point ranges the server stores, re-read off the body
                        // going with them.
                        transform((form) => ({ ...form, mentions: toPayload(form.mentions, form.body) }));
                        // forceFormData: the upload needs a multipart body, which Inertia uses
                        // automatically once a File is present but not for an initially-null field.
                        post('/timeline/create', { forceFormData: true });
                    }}
                    className="space-y-4"
                >
                    <Field
                        label={t('Body')}
                        htmlFor="timeline_body"
                        error={errors.body}
                        labelRight={<BodyCounter id={counterId} body={data.body} />}
                    >
                        <MentionTextarea
                            id="timeline_body"
                            required
                            rows={4}
                            aria-describedby={counterId}
                            value={data.body}
                            onChange={(body) => setData('body', body)}
                            mentions={data.mentions}
                            onMentionsChange={(mentions) => setData('mentions', mentions)}
                        />
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
                    <Button type="submit" className="max-lg:hidden" loading={processing} disabled={tooLong}>
                        {t('%Post_activity%')}
                    </Button>
                </form>
            </Panel>
        </>
    );
}
