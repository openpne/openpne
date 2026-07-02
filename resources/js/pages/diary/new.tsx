import { Head, useForm, usePage } from '@inertiajs/react';
import { FlashMessage } from '@/components/flash-message';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';

type VisibilityOption = { value: string; label: string };

export default function DiaryNew({
    defaultVisibility,
    visibilityOptions,
}: {
    defaultVisibility: string;
    visibilityOptions: VisibilityOption[];
}) {
    const t = useT();
    const { flash } = usePage<PageProps>().props;
    const { data, setData, post, errors, processing } = useForm({
        title: '',
        body: '',
        visibility: defaultVisibility,
        images: [] as File[],
    });

    return (
        <>
            <Head title={t('Write a %diary%')} />
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8">
                <h1 className="text-xl font-semibold text-foreground">{t('Write a %diary%')}</h1>

                {flash.error && <FlashMessage variant="error">{flash.error}</FlashMessage>}

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        // forceFormData: the upload needs a multipart body, which Inertia uses
                        // automatically once a File is present but not for an initially-empty array.
                        post('/m/diary/create', { forceFormData: true });
                    }}
                    className="space-y-4"
                >
                    <Field label={t('Title')} htmlFor="diary_title" error={errors.title}>
                        <Input id="diary_title" type="text" required value={data.title} onChange={(e) => setData('title', e.target.value)} />
                    </Field>

                    <Field label={t('Body')} htmlFor="diary_body" error={errors.body}>
                        <Textarea id="diary_body" required rows={10} value={data.body} onChange={(e) => setData('body', e.target.value)} />
                    </Field>

                    <Field label={t('Visibility')} htmlFor="diary_visibility" error={errors.visibility}>
                        <Select id="diary_visibility" value={data.visibility} onChange={(e) => setData('visibility', e.target.value)}>
                            {visibilityOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {t(option.label)}
                                </option>
                            ))}
                        </Select>
                    </Field>

                    <Field label={t('Images')} htmlFor="diary_images" error={errors.images}>
                        <input
                            id="diary_images"
                            type="file"
                            accept="image/jpeg,image/png,image/gif,image/webp"
                            multiple
                            onChange={(e) => setData('images', Array.from(e.target.files ?? []).slice(0, 3))}
                            className="block w-full text-sm text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-secondary file:px-3 file:py-2 file:text-sm file:font-medium file:text-secondary-foreground hover:file:bg-secondary/80"
                        />
                    </Field>

                    <Button type="submit" loading={processing}>
                        {t('Post')}
                    </Button>
                </form>
            </main>
        </>
    );
}
