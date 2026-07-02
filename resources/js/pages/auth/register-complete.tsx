import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { AuthLayout } from '@/layouts/auth-layout';
import { useT } from '@/lib/i18n';
import { ProfileFieldInput } from '@/pages/member/profile-field-input';
import type { ProfileFormField } from '@/pages/member/types';
import type { PageProps } from '@/types';

interface RegisterCompleteProps extends PageProps {
    /** Raw token from the URL; posted back to /register/{token}. */
    token: string;
    /** Address fixed by the token — shown, never entered. */
    email: string;
    fields: ProfileFormField[];
}

export default function RegisterComplete() {
    const t = useT();
    const { token, email, fields } = usePage<RegisterCompleteProps>().props;
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        password: '',
        password_confirmation: '',
        profile: Object.fromEntries(fields.map((f) => [f.id, f.value])) as Record<number, string | string[]>,
        visibility: Object.fromEntries(
            fields.filter((f) => f.is_edit_public_flag).map((f) => [f.id, f.visibility]),
        ) as Record<number, number>,
    });

    function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        post(`/register/${token}`, {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    }

    const setProfile = (id: number, value: string | string[]) => setData('profile', { ...data.profile, [id]: value });
    const setVisibility = (id: number, value: number) => setData('visibility', { ...data.visibility, [id]: value });

    const title = t('Create an account');

    return (
        <AuthLayout title={title}>
            <Head title={title} />

            <form onSubmit={submit} className="space-y-4">
                <div className="space-y-1">
                    <span className="block text-sm font-medium text-foreground">{t('Mail Address')}</span>
                    <p className="text-sm text-muted-foreground">{email}</p>
                </div>

                <Field label={t('Name')} htmlFor="name" error={errors.name}>
                    <Input id="name" type="text" name="name" autoComplete="name" autoFocus required value={data.name} onChange={(e) => setData('name', e.target.value)} />
                </Field>

                <Field label={t('Password')} htmlFor="password" error={errors.password}>
                    <Input id="password" type="password" name="password" autoComplete="new-password" required value={data.password} onChange={(e) => setData('password', e.target.value)} />
                </Field>

                <Field label={t('Confirm password')} htmlFor="password_confirmation">
                    <Input id="password_confirmation" type="password" name="password_confirmation" autoComplete="new-password" required value={data.password_confirmation} onChange={(e) => setData('password_confirmation', e.target.value)} />
                </Field>

                {fields.map((field) => (
                    <div key={field.id} className="space-y-1">
                        <ProfileFieldInput
                            field={field}
                            value={data.profile[field.id] ?? ''}
                            onChange={(next) => setProfile(field.id, next)}
                            error={(errors as Record<string, string>)[`profile.${field.id}`]}
                        />
                        {field.is_edit_public_flag && (
                            <Select
                                aria-label={t('Visibility')}
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

                <Button type="submit" loading={processing} className="w-full">
                    {title}
                </Button>
            </form>
        </AuthLayout>
    );
}
