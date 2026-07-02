import { Head, router, useForm, usePage } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { useConfirm } from '@/components/confirm-dialog';
import { FlashMessage } from '@/components/flash-message';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface EditCommunity {
    id: number;
    name: string;
    description: string;
    registerPolicy: number;
    categoryId: number | null;
    imageUrl: string | null;
}

interface EditProps extends PageProps {
    community: EditCommunity | null; // null = create mode
    categories: { id: number; name: string }[];
    policies: { value: number; label: string }[];
    canDelete: boolean;
}

export default function CommunityEdit() {
    const t = useT();
    const confirm = useConfirm();
    const { community, categories, policies, canDelete, flash } = usePage<EditProps>().props;
    const isEdit = community !== null;

    const form = useForm({
        name: community?.name ?? '',
        description: community?.description ?? '',
        register_policy: community?.registerPolicy ?? policies[0]?.value ?? 1,
        community_category_id: community?.categoryId ? String(community.categoryId) : '',
        image: null as File | null,
        remove_image: false,
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(isEdit ? `/m/community/edit?id=${community.id}` : '/m/community/edit', { forceFormData: true });
    };

    const destroy = async () => {
        if (!isEdit) return;
        if (
            await confirm({
                title: t('Delete this %community%?'),
                description: t('This cannot be undone.'),
                confirmLabel: t('Delete'),
                danger: true,
            })
        ) {
            router.post(`/m/community/${community.id}/delete`);
        }
    };

    const title = isEdit ? t('Edit %community%') : t('Create a %community%');

    return (
        <>
            <Head title={title} />
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8">
                <h1 className="text-xl font-semibold text-foreground">{title}</h1>

                {flash.error && <FlashMessage variant="error">{flash.error}</FlashMessage>}

                <form onSubmit={submit} className="space-y-4">
                    <Field label={t('Name')} htmlFor="name" error={form.errors.name}>
                        <Input
                            id="name"
                            type="text"
                            maxLength={64}
                            required
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                        />
                    </Field>

                    <Field label={t('Description')} htmlFor="description">
                        <Textarea
                            id="description"
                            rows={5}
                            value={form.data.description}
                            onChange={(e) => form.setData('description', e.target.value)}
                        />
                    </Field>

                    <Field label={t('Join policy')} htmlFor="register_policy">
                        <Select
                            id="register_policy"
                            value={form.data.register_policy}
                            onChange={(e) => form.setData('register_policy', Number(e.target.value))}
                        >
                            {policies.map((policy) => (
                                <option key={policy.value} value={policy.value}>
                                    {t(policy.label)}
                                </option>
                            ))}
                        </Select>
                    </Field>

                    <Field label={t('Category')} htmlFor="community_category_id">
                        <Select
                            id="community_category_id"
                            value={form.data.community_category_id}
                            onChange={(e) => form.setData('community_category_id', e.target.value)}
                        >
                            <option value="">{t('No category')}</option>
                            {categories.map((category) => (
                                <option key={category.id} value={category.id}>
                                    {category.name}
                                </option>
                            ))}
                        </Select>
                    </Field>

                    <Field label={t('Image')} htmlFor="image" error={form.errors.image}>
                        {community?.imageUrl && (
                            <div className="mb-2 flex items-center gap-3">
                                <img src={community.imageUrl} alt="" className="size-20 rounded-md object-cover" />
                                <label className="flex items-center gap-1 text-sm text-foreground">
                                    <Checkbox
                                        checked={form.data.remove_image}
                                        onChange={(e) => form.setData('remove_image', e.target.checked)}
                                    />
                                    {t('Delete')}
                                </label>
                            </div>
                        )}
                        <input
                            id="image"
                            type="file"
                            accept="image/jpeg,image/png,image/gif,image/webp"
                            onChange={(e) => form.setData('image', e.target.files?.[0] ?? null)}
                            className="block w-full text-sm text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-secondary file:px-3 file:py-2 file:text-sm file:font-medium file:text-secondary-foreground hover:file:bg-secondary/80"
                        />
                    </Field>

                    <Button type="submit" loading={form.processing}>
                        {t('Save')}
                    </Button>
                </form>

                {isEdit && canDelete && (
                    <div className="border-t border-border pt-4">
                        <Button type="button" variant="destructive" onClick={destroy}>
                            {t('Delete this %community%')}
                        </Button>
                    </div>
                )}
            </main>
        </>
    );
}
