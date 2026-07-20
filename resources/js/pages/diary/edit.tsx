import { Head, useForm, usePage } from '@inertiajs/react';
import { CurrentImagesField } from '@/components/current-images-field';
import { ImagesField } from '@/components/images-field';
import { MarkdownPreview } from '@/components/markdown-preview';
import { MarkdownToggle } from '@/components/markdown-toggle';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { Panel } from '@/components/ui/surface';
import { Textarea } from '@/components/ui/textarea';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { DiaryDetail } from './types';

interface EditProps extends PageProps {
    diary: DiaryDetail;
}

export default function DiaryEdit() {
    const t = useT();
    const { diary } = usePage<EditProps>().props;
    // op3 is a migration-only format with no author-facing editor: omit `format` entirely so the
    // update preserves it (an absent field preserves the current format server-side).
    const isOp3 = diary.format === 'op3';
    const { data, setData, post, errors, processing } = useForm({
        title: diary.title,
        body: diary.body,
        visibility: String(
            diary.visibility === 'private' ? 3 : diary.visibility === 'friends' ? 2 : 1,
        ),
        images: [] as File[],
        remove_images: [] as number[],
        ...(isOp3 ? {} : { format: (diary.format === 'markdown' ? 'markdown' : 'plain') as 'plain' | 'markdown' }),
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

            <Panel>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        post(`/diary/update/${diary.id}`, { forceFormData: true });
                    }}
                    className="space-y-4"
                >
                    <Field label={t('Title')} htmlFor="diary_title" error={errors.title}>
                        <Input id="diary_title" type="text" required value={data.title} onChange={(e) => setData('title', e.target.value)} />
                    </Field>

                    <Field label={t('Body')} htmlFor="diary_body" error={errors.body}>
                        <Textarea id="diary_body" required rows={10} value={data.body} onChange={(e) => setData('body', e.target.value)} />
                    </Field>

                    {isOp3 ? (
                        <p className="text-sm text-muted-foreground">{t('This entry keeps its OpenPNE 3 formatting.')}</p>
                    ) : (
                        <>
                            <MarkdownToggle checked={data.format === 'markdown'} onChange={(on) => setData('format', on ? 'markdown' : 'plain')} />
                            <MarkdownPreview body={data.body} enabled={data.format === 'markdown'} />
                        </>
                    )}

                    <Field label={t('Visibility')} htmlFor="diary_visibility" error={errors.visibility}>
                        <Select id="diary_visibility" value={data.visibility} onChange={(e) => setData('visibility', e.target.value)}>
                            <option value="1">{t('All members')}</option>
                            <option value="2">{t('%Friends% only')}</option>
                            <option value="3">{t('Private')}</option>
                        </Select>
                    </Field>

                    <CurrentImagesField images={diary.images} removedIds={data.remove_images} onToggle={toggleRemove} />

                    <ImagesField id="diary_images" label={t('Images')} files={data.images} onChange={(files) => setData('images', files)} errors={errors} />

                    <Button type="submit" loading={processing}>
                        {t('Save')}
                    </Button>
                </form>
            </Panel>
        </>
    );
}
