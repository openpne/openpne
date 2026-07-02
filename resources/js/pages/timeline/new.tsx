import { Head, useForm, usePage } from '@inertiajs/react';
import { FlashMessage } from '@/components/flash-message';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Select } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';

type VisibilityOption = { value: string; label: string };

export default function TimelineNew({
    defaultVisibility,
    visibilityOptions,
}: {
    defaultVisibility: string;
    visibilityOptions: VisibilityOption[];
}) {
    const t = useT();
    const { flash } = usePage<PageProps>().props;
    const { data, setData, post, errors, processing } = useForm({
        body: '',
        visibility: defaultVisibility,
        image: null as File | null,
    });

    return (
        <>
            <Head title={t('%Post_activity%')} />
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8">
                <h1 className="text-xl font-semibold text-foreground">{t('%Post_activity%')}</h1>

                {flash.error && <FlashMessage variant="error">{flash.error}</FlashMessage>}

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        // forceFormData: the upload needs a multipart body, which Inertia uses
                        // automatically once a File is present but not for an initially-null field.
                        post('/m/timeline/create', { forceFormData: true });
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
                            className="block w-full text-sm text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-secondary file:px-3 file:py-2 file:text-sm file:font-medium file:text-secondary-foreground hover:file:bg-secondary/80"
                        />
                    </Field>

                    <Button type="submit" loading={processing}>
                        {t('%Post_activity%')}
                    </Button>
                </form>
            </main>
        </>
    );
}
