import { Head, useForm, usePage } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { CurrentImagesField } from '@/components/current-images-field';
import { ImagesField } from '@/components/images-field';
import { MarkdownPreview } from '@/components/markdown-preview';
import { MarkdownToggle } from '@/components/markdown-toggle';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Panel } from '@/components/ui/surface';
import { Textarea } from '@/components/ui/textarea';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { CommunitySummary, TopicDetail } from '../types';

interface EditProps extends PageProps {
    community: CommunitySummary;
    topic: TopicDetail | null; // null = create mode
}

export default function CommunityTopicEdit() {
    const t = useT();
    const { community, topic } = usePage<EditProps>().props;
    const isEdit = topic !== null;
    // op3 is a migration-only format with no author-facing editor: omit `format` so the update
    // preserves it (an absent field preserves the current format server-side).
    const isOp3 = topic?.format === 'op3';

    const form = useForm({
        name: topic?.name ?? '',
        body: topic?.body ?? '',
        images: [] as File[],
        remove_images: [] as number[],
        ...(isOp3 ? {} : { format: (topic?.format === 'markdown' ? 'markdown' : 'plain') as 'plain' | 'markdown' }),
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(isEdit ? `/communityTopic/update/${topic.id}` : `/communityTopic/create/${community.id}`, {
            forceFormData: true,
        });
    };

    const toggleRemove = (imageId: number, remove: boolean) => {
        form.setData('remove_images', remove ? [...form.data.remove_images, imageId] : form.data.remove_images.filter((id) => id !== imageId));
    };

    const title = isEdit ? t('Edit %topic%') : t('Create a %topic%');

    return (
        <>
            <Head title={title} />
            <h1 className="break-words text-xl font-semibold text-foreground">{title}</h1>

            <Panel>
                <form onSubmit={submit} className="space-y-4">
                    <Field label={t('Title')} htmlFor="name" error={form.errors.name}>
                        <Input id="name" type="text" required value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                    </Field>

                    <Field label={t('Body')} htmlFor="body" error={form.errors.body}>
                        <Textarea id="body" required rows={10} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} />
                    </Field>

                    {isOp3 ? (
                        <p className="text-sm text-muted-foreground">{t('This entry keeps its OpenPNE 3 formatting.')}</p>
                    ) : (
                        <>
                            <MarkdownToggle checked={form.data.format === 'markdown'} onChange={(on) => form.setData('format', on ? 'markdown' : 'plain')} />
                            <MarkdownPreview body={form.data.body} enabled={form.data.format === 'markdown'} />
                        </>
                    )}

                    <CurrentImagesField images={topic?.images ?? []} removedIds={form.data.remove_images} onToggle={toggleRemove} />

                    <ImagesField id="images" label={t('Add images')} files={form.data.images} onChange={(files) => form.setData('images', files)} errors={form.errors} />

                    <Button
                        type="submit"
                        loading={form.processing}
                        disabled={form.data.name.trim() === '' || form.data.body.trim() === ''}
                    >
                        {isEdit ? t('Save') : t('Post')}
                    </Button>
                </form>
            </Panel>
        </>
    );
}
