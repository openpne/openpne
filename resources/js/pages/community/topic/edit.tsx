import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
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

    const form = useForm({
        name: topic?.name ?? '',
        body: topic?.body ?? '',
        images: [] as File[],
        remove_images: [] as number[],
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(isEdit ? `/m/community/topic/${topic.id}/edit` : `/m/community/${community.id}/topic`, {
            forceFormData: true,
        });
    };

    const toggleRemove = (imageId: number, remove: boolean) => {
        form.setData('remove_images', remove ? [...form.data.remove_images, imageId] : form.data.remove_images.filter((id) => id !== imageId));
    };

    const title = isEdit ? t('Edit %topic%') : t('Post a new %topic%');
    const backHref = isEdit ? `/m/community/topic/${topic.id}` : `/m/community/${community.id}/topic`;

    return (
        <>
            <Head title={title} />
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8">
                <p className="text-sm">
                    <Link href={backHref} className="text-muted-foreground hover:text-foreground hover:underline">
                        {community.name}
                    </Link>
                </p>
                <h1 className="text-xl font-semibold text-foreground">{title}</h1>

                <form onSubmit={submit} className="space-y-4">
                    <Field label={t('Title')} htmlFor="name" error={form.errors.name}>
                        <Input id="name" type="text" required value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                    </Field>

                    <Field label={t('Body')} htmlFor="body" error={form.errors.body}>
                        <Textarea id="body" required rows={10} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} />
                    </Field>

                    {isEdit && topic.images.length > 0 && (
                        <fieldset className="space-y-2">
                            <legend className="text-sm font-medium text-foreground">{t('Current images')}</legend>
                            <ul className="flex flex-wrap gap-3">
                                {topic.images.map((image) => (
                                    <li key={image.id} className="space-y-1 text-center">
                                        <img src={image.thumbnailUrl} alt="" className="size-24 rounded-md object-cover" />
                                        <label className="flex items-center justify-center gap-1 text-sm text-foreground">
                                            <Checkbox
                                                checked={form.data.remove_images.includes(image.id)}
                                                onChange={(e) => toggleRemove(image.id, e.target.checked)}
                                            />
                                            {t('Delete')}
                                        </label>
                                    </li>
                                ))}
                            </ul>
                        </fieldset>
                    )}

                    <Field label={t('Add images')} htmlFor="images" error={form.errors.images}>
                        <input
                            id="images"
                            type="file"
                            accept="image/jpeg,image/png,image/gif,image/webp"
                            multiple
                            onChange={(e) => form.setData('images', Array.from(e.target.files ?? []).slice(0, 3))}
                            className="block w-full text-sm text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-secondary file:px-3 file:py-2 file:text-sm file:font-medium file:text-secondary-foreground hover:file:bg-secondary/80"
                        />
                    </Field>

                    <Button
                        type="submit"
                        loading={form.processing}
                        disabled={form.data.name.trim() === '' || form.data.body.trim() === ''}
                    >
                        {isEdit ? t('Save') : t('Post')}
                    </Button>
                </form>
            </main>
        </>
    );
}
