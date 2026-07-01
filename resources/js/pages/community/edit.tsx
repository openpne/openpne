import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useConfirm } from '@/components/confirm-dialog';
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

    const submit = (e: React.FormEvent) => {
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
                <h1 className="text-2xl font-semibold">{title}</h1>

                {flash.error && <p role="alert">{flash.error}</p>}

                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-1">
                        <label htmlFor="name">{t('Name')}</label>
                        <input
                            id="name"
                            type="text"
                            maxLength={64}
                            required
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            className="w-full rounded border px-2 py-1"
                        />
                        {form.errors.name && <p role="alert">{form.errors.name}</p>}
                    </div>

                    <div className="space-y-1">
                        <label htmlFor="description">{t('Description')}</label>
                        <textarea
                            id="description"
                            rows={5}
                            value={form.data.description}
                            onChange={(e) => form.setData('description', e.target.value)}
                            className="w-full rounded border px-2 py-1"
                        />
                    </div>

                    <div className="space-y-1">
                        <label htmlFor="register_policy">{t('Join policy')}</label>
                        <select
                            id="register_policy"
                            value={form.data.register_policy}
                            onChange={(e) => form.setData('register_policy', Number(e.target.value))}
                            className="block rounded border px-2 py-1"
                        >
                            {policies.map((policy) => (
                                <option key={policy.value} value={policy.value}>
                                    {t(policy.label)}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="space-y-1">
                        <label htmlFor="community_category_id">{t('Category')}</label>
                        <select
                            id="community_category_id"
                            value={form.data.community_category_id}
                            onChange={(e) => form.setData('community_category_id', e.target.value)}
                            className="block rounded border px-2 py-1"
                        >
                            <option value="">{t('No category')}</option>
                            {categories.map((category) => (
                                <option key={category.id} value={category.id}>
                                    {category.name}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="space-y-1">
                        <label htmlFor="image">{t('Image')}</label>
                        {community?.imageUrl && (
                            <div className="flex items-center gap-3">
                                <img src={community.imageUrl} alt="" className="size-20 rounded-lg object-cover" />
                                <label className="flex items-center gap-1 text-sm">
                                    <input
                                        type="checkbox"
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
                        />
                        {form.errors.image && <p role="alert">{form.errors.image}</p>}
                    </div>

                    <button
                        type="submit"
                        disabled={form.processing}
                        className="min-h-11 rounded-full bg-blue-600 px-5 text-sm font-medium text-white transition hover:bg-blue-700"
                    >
                        {t('Save')}
                    </button>
                </form>

                {isEdit && canDelete && (
                    <div className="border-t border-slate-200 pt-4 dark:border-slate-700">
                        <button
                            type="button"
                            onClick={destroy}
                            className="min-h-11 rounded-full bg-red-50 px-5 text-sm font-medium text-red-700 transition hover:bg-red-100 dark:bg-red-950 dark:text-red-300 dark:hover:bg-red-900"
                        >
                            {t('Delete this %community%')}
                        </button>
                    </div>
                )}
            </main>
        </>
    );
}
