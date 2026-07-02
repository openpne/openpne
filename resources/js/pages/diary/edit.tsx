import { Head, useForm, usePage } from '@inertiajs/react';
import { FlashMessage } from '@/components/flash-message';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { DiaryDetail } from './types';

interface EditProps extends PageProps {
    diary: DiaryDetail;
}

export default function DiaryEdit() {
    const t = useT();
    const { diary, flash } = usePage<EditProps>().props;
    const { data, setData, post, errors, processing } = useForm({
        title: diary.title,
        body: diary.body,
        visibility: String(
            diary.visibility === 'private' ? 3 : diary.visibility === 'friends' ? 2 : 1,
        ),
        images: [] as File[],
        remove_images: [] as number[],
    });

    const toggleRemove = (id: number, remove: boolean) =>
        setData(
            'remove_images',
            remove ? [...data.remove_images, id] : data.remove_images.filter((x) => x !== id),
        );

    return (
        <>
            <Head title={t('Edit %diary%')} />
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8">
                <h1 className="text-xl font-semibold text-foreground">{t('Edit %diary%')}</h1>

                {flash.error && <FlashMessage variant="error">{flash.error}</FlashMessage>}

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        post(`/m/diary/update/${diary.id}`, { forceFormData: true });
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
                            <option value="1">{t('All members')}</option>
                            <option value="2">{t('%Friends% only')}</option>
                            <option value="3">{t('Private')}</option>
                        </Select>
                    </Field>

                    {diary.images.length > 0 && (
                        <fieldset className="space-y-2">
                            <legend className="text-sm font-medium text-foreground">{t('Current images')}</legend>
                            <ul className="flex flex-wrap gap-3">
                                {diary.images.map((image) => (
                                    <li key={image.id} className="space-y-1 text-center">
                                        <img src={image.thumbnailUrl} alt="" className="size-24 rounded-md object-cover" />
                                        <label className="flex items-center justify-center gap-1 text-sm text-foreground">
                                            <Checkbox onChange={(e) => toggleRemove(image.id, e.target.checked)} />
                                            {t('Delete')}
                                        </label>
                                    </li>
                                ))}
                            </ul>
                        </fieldset>
                    )}

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
                        {t('Save')}
                    </Button>
                </form>
            </main>
        </>
    );
}
