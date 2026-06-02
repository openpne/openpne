import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { AuthLayout } from '@/layouts/auth-layout';
import { useT } from '@/lib/i18n';
import { ProfileFieldInput } from '@/pages/member/profile-field-input';
import type { ProfileFormField } from '@/pages/member/types';
import type { PageProps } from '@/types';

interface RegisterProps extends PageProps {
    profileFields: ProfileFormField[];
}

export default function Register() {
    const t = useT();
    const { profileFields } = usePage<RegisterProps>().props;
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        profile: Object.fromEntries(profileFields.map((f) => [f.id, f.value])) as Record<number, string | string[]>,
        visibility: Object.fromEntries(
            profileFields.filter((f) => f.is_edit_public_flag).map((f) => [f.id, f.visibility]),
        ) as Record<number, number>,
    });

    function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        post('/register', {
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
                    <label htmlFor="name" className="block text-sm font-medium">
                        {t('Name')}
                    </label>
                    <input
                        id="name"
                        type="text"
                        name="name"
                        autoComplete="name"
                        autoFocus
                        required
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring"
                    />
                    {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
                </div>

                <div className="space-y-1">
                    <label htmlFor="email" className="block text-sm font-medium">
                        {t('Email')}
                    </label>
                    <input
                        id="email"
                        type="email"
                        name="email"
                        autoComplete="email"
                        required
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring"
                    />
                    {errors.email && <p className="text-sm text-destructive">{errors.email}</p>}
                </div>

                <div className="space-y-1">
                    <label htmlFor="password" className="block text-sm font-medium">
                        {t('Password')}
                    </label>
                    <input
                        id="password"
                        type="password"
                        name="password"
                        autoComplete="new-password"
                        required
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring"
                    />
                    {errors.password && <p className="text-sm text-destructive">{errors.password}</p>}
                </div>

                <div className="space-y-1">
                    <label htmlFor="password_confirmation" className="block text-sm font-medium">
                        {t('Confirm password')}
                    </label>
                    <input
                        id="password_confirmation"
                        type="password"
                        name="password_confirmation"
                        autoComplete="new-password"
                        required
                        value={data.password_confirmation}
                        onChange={(e) => setData('password_confirmation', e.target.value)}
                        className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring"
                    />
                </div>

                {profileFields.map((field) => (
                    <div key={field.id} className="space-y-1">
                        <ProfileFieldInput
                            field={field}
                            value={data.profile[field.id] ?? ''}
                            onChange={(next) => setProfile(field.id, next)}
                            error={(errors as Record<string, string>)[`profile.${field.id}`]}
                        />
                        {field.is_edit_public_flag && (
                            <select
                                aria-label={t('Visibility')}
                                value={data.visibility[field.id]}
                                onChange={(e) => setVisibility(field.id, Number(e.target.value))}
                            >
                                {field.visibilityOptions.map((opt) => (
                                    <option key={opt.value} value={opt.value}>{t(opt.label)}</option>
                                ))}
                            </select>
                        )}
                    </div>
                ))}

                <button
                    type="submit"
                    disabled={processing}
                    className="w-full rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50"
                >
                    {title}
                </button>

                <p className="text-center text-sm text-muted-foreground">
                    <Link href="/login" className="underline">
                        {t('Already have an account? Sign in')}
                    </Link>
                </p>
            </form>
        </AuthLayout>
    );
}
