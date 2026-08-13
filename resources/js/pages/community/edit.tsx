import { Head, router, useForm, usePage } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { useConfirm } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field } from '@/components/ui/field';
import { Heading } from '@/components/ui/heading';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { Panel } from '@/components/ui/surface';
import { Textarea } from '@/components/ui/textarea';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface EditCommunity {
    id: number;
    name: string;
    description: string;
    registerPolicy: string;
    categoryId: number | null;
    isJoinNotificationEnabled: boolean;
    topicReadAccess: string;
    topicPostAuthority: string;
    imageUrl: string | null;
}

/** One enum choice, slug on the wire and the OpenPNE 3 caption as its label. */
interface Choice {
    slug: string;
    label: string;
}

interface EditProps extends PageProps {
    group: EditCommunity | null; // null = create mode
    categories: { id: number; name: string }[];
    policies: Choice[];
    topicReadChoices: Choice[];
    topicPostChoices: Choice[];
    canDelete: boolean;
}

export default function CommunityEdit() {
    const t = useT();
    const confirm = useConfirm();
    const { group, categories, policies, topicReadChoices, topicPostChoices, canDelete } = usePage<EditProps>().props;
    const isEdit = group !== null;

    const form = useForm({
        name: group?.name ?? '',
        description: group?.description ?? '',
        register_policy: group?.registerPolicy ?? policies[0]?.slug ?? 'open',
        community_category_id: group?.categoryId ? String(group.categoryId) : '',
        is_join_notification_enabled: group?.isJoinNotificationEnabled ?? true,
        topic_read_access: group?.topicReadAccess ?? topicReadChoices[0]?.slug ?? 'everyone',
        topic_post_authority: group?.topicPostAuthority ?? topicPostChoices[0]?.slug ?? 'members',
        image: null as File | null,
        remove_image: false,
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(isEdit ? `/groups/edit?id=${group.id}` : '/groups/edit', { forceFormData: true });
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
            router.post(`/groups/${group.id}/delete`);
        }
    };

    const title = isEdit ? t('Edit %community%') : t('Create a %community%');

    return (
        <>
            <Head title={title} />
            <Heading variant="page">{title}</Heading>

            <Panel>
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
                            onChange={(e) => form.setData('register_policy', e.target.value)}
                        >
                            {policies.map((policy) => (
                                <option key={policy.slug} value={policy.slug}>
                                    {t(policy.label)}
                                </option>
                            ))}
                        </Select>
                    </Field>

                    <Field label={t('Authority to Read %Topic%')} htmlFor="topic_read_access">
                        <Select
                            id="topic_read_access"
                            value={form.data.topic_read_access}
                            onChange={(e) => form.setData('topic_read_access', e.target.value)}
                        >
                            {topicReadChoices.map((choice) => (
                                <option key={choice.slug} value={choice.slug}>
                                    {t(choice.label)}
                                </option>
                            ))}
                        </Select>
                    </Field>

                    <Field label={t('Authority to Create %Topic%')} htmlFor="topic_post_authority">
                        <Select
                            id="topic_post_authority"
                            value={form.data.topic_post_authority}
                            onChange={(e) => form.setData('topic_post_authority', e.target.value)}
                        >
                            {topicPostChoices.map((choice) => (
                                <option key={choice.slug} value={choice.slug}>
                                    {t(choice.label)}
                                </option>
                            ))}
                        </Select>
                    </Field>

                    <label className="flex items-center gap-2 text-sm text-foreground">
                        <Checkbox
                            checked={form.data.is_join_notification_enabled}
                            onChange={(e) => form.setData('is_join_notification_enabled', e.target.checked)}
                        />
                        {t('Notify admins when a member joins.')}
                    </label>

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

                    {/* Field wraps only the file input (single control) so its id/aria wiring reaches it;
                        the existing-image + remove control is a sibling below, not a second child. */}
                    <div className="space-y-2">
                        <Field label={t('Image')} htmlFor="image" error={form.errors.image}>
                            <input
                                id="image"
                                type="file"
                                accept="image/jpeg,image/png,image/gif,image/webp"
                                onChange={(e) => form.setData('image', e.target.files?.[0] ?? null)}
                                className="block w-full text-sm text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-secondary file:px-3 file:py-2 file:text-sm file:text-secondary-foreground hover:file:bg-secondary/80"
                            />
                        </Field>
                        {group?.imageUrl && (
                            <div className="flex items-center gap-3">
                                <img src={group.imageUrl} alt="" className="size-20 rounded-md object-cover" />
                                <label className="flex items-center gap-1 text-sm text-foreground">
                                    <Checkbox
                                        checked={form.data.remove_image}
                                        onChange={(e) => form.setData('remove_image', e.target.checked)}
                                    />
                                    {t('Delete')}
                                </label>
                            </div>
                        )}
                    </div>

                    <Button type="submit" loading={form.processing}>
                        {t('Save')}
                    </Button>
                </form>
            </Panel>

            {isEdit && canDelete && (
                <div className="flex">
                    <Button type="button" variant="destructive" onClick={destroy}>
                        {t('Delete this %community%')}
                    </Button>
                </div>
            )}
        </>
    );
}
