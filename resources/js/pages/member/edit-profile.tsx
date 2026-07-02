import { Head, useForm, usePage } from '@inertiajs/react';
import { FlashMessage } from '@/components/flash-message';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import { ProfileFieldInput } from './profile-field-input';
import type { ProfileForm } from './types';

interface EditProps extends PageProps {
    form: ProfileForm;
}

export default function MemberEditProfile() {
    const t = useT();
    const { form, flash } = usePage<EditProps>().props;

    const { data, setData, post, errors, processing } = useForm({
        name: form.name,
        profile: Object.fromEntries(form.fields.map((f) => [f.id, f.value])) as Record<number, string | string[]>,
        visibility: Object.fromEntries(
            form.fields.filter((f) => f.is_edit_public_flag).map((f) => [f.id, f.visibility]),
        ) as Record<number, number>,
    });

    const setProfile = (id: number, value: string | string[]) =>
        setData('profile', { ...data.profile, [id]: value });
    const setVisibility = (id: number, value: number) =>
        setData('visibility', { ...data.visibility, [id]: value });

    return (
        <>
            <Head title={t('Edit Profile')} />
            <main className="mx-auto max-w-2xl space-y-6 px-4 py-8">
                <h1 className="text-xl font-semibold text-foreground">{t('Edit Profile')}</h1>

                {flash.status && <FlashMessage>{flash.status}</FlashMessage>}

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        post('/m/member/edit/profile');
                    }}
                    className="space-y-5"
                >
                    <Field label={t('%nickname%')} htmlFor="member_name" required error={errors.name}>
                        <Input id="member_name" type="text" maxLength={255} required value={data.name} onChange={(e) => setData('name', e.target.value)} />
                    </Field>

                    {form.fields.map((field) => (
                        <div key={field.id} className="space-y-1.5">
                            <ProfileFieldInput
                                field={field}
                                value={data.profile[field.id] ?? ''}
                                onChange={(next) => setProfile(field.id, next)}
                                error={(errors as Record<string, string>)[`profile.${field.id}`]}
                            />
                            {field.is_edit_public_flag && (
                                <Select
                                    aria-label={t('Visibility')}
                                    className="max-w-xs"
                                    value={data.visibility[field.id]}
                                    onChange={(e) => setVisibility(field.id, Number(e.target.value))}
                                >
                                    {field.visibilityOptions.map((opt) => (
                                        <option key={opt.value} value={opt.value}>{t(opt.label)}</option>
                                    ))}
                                </Select>
                            )}
                        </div>
                    ))}

                    <Button type="submit" loading={processing}>{t('Update')}</Button>
                </form>
            </main>
        </>
    );
}
