import { Head, useForm, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Card, CardBody } from '@/components/card';
import { FlashMessage } from '@/components/flash-message';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FormActions, FormSection } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { RadioCard } from '@/components/ui/radio-card';
import { Select } from '@/components/ui/select';
import { type ColorMode, useColorMode } from '@/lib/color-mode';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';

const APPEARANCE_OPTIONS: { value: ColorMode; label: string }[] = [
    { value: 'light', label: 'Light' },
    { value: 'dark', label: 'Dark' },
    { value: 'system', label: 'Use system setting' },
];

interface Option {
    value: string;
    label: string;
    description?: string;
}

interface ConfigForm {
    diary: { value: string; options: Option[] };
    age: { value: string; options: Option[] };
    email: { value: string };
    locale: { value: string; options: Option[] };
    surface: { value: string; options: Option[] };
}

interface ConfigProps extends PageProps {
    form: ConfigForm;
}

function SectionCard({ children }: { children: ReactNode }) {
    return (
        <Card>
            <CardBody>{children}</CardBody>
        </Card>
    );
}

export default function MemberConfig() {
    const t = useT();
    const { form, flash } = usePage<ConfigProps>().props;

    // One form per section so saving one never resubmits another (mirrors the Classic surface).
    const diary = useForm({ diary_default_visibility: form.diary.value });
    const age = useForm({ age_visibility: form.age.value });
    const locale = useForm({ locale: form.locale.value });
    const surface = useForm({ preferred_surface: form.surface.value });
    const password = useForm({ current_password: '', password: '', password_confirmation: '' });
    const email = useForm({ new_email: '', password: '' });
    const withdraw = useForm({ password: '', confirm: false });
    // Appearance is a client-side display preference (localStorage), applied immediately — no server post.
    const { preference, set: setColorMode } = useColorMode();

    return (
        <>
            <Head title={t('Settings')} />
            <main className="mx-auto max-w-2xl space-y-6 px-4 py-8">
                <h1 className="text-xl font-semibold text-foreground">{t('Settings')}</h1>

                {flash.status && <FlashMessage>{flash.status}</FlashMessage>}
                {flash.error && <FlashMessage variant="error">{flash.error}</FlashMessage>}

                <SectionCard>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            diary.post('/m/member/config/diary');
                        }}
                    >
                        <FormSection title={t('Diary')}>
                            <Field
                                label={t('Default audience for new diaries')}
                                htmlFor="diary_default_visibility"
                                error={diary.errors.diary_default_visibility}
                            >
                                <Select
                                    id="diary_default_visibility"
                                    aria-invalid={!!diary.errors.diary_default_visibility}
                                    value={diary.data.diary_default_visibility}
                                    onChange={(e) => diary.setData('diary_default_visibility', e.target.value)}
                                >
                                    {form.diary.options.map((opt) => (
                                        <option key={opt.value} value={opt.value}>
                                            {t(opt.label)}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            <FormActions>
                                <Button type="submit" loading={diary.processing}>
                                    {t('Save')}
                                </Button>
                            </FormActions>
                        </FormSection>
                    </form>
                </SectionCard>

                <SectionCard>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            age.post('/m/member/config/age');
                        }}
                    >
                        <FormSection title={t('Age')}>
                            <Field label={t('Who can see your age')} htmlFor="age_visibility" error={age.errors.age_visibility}>
                                <Select
                                    id="age_visibility"
                                    aria-invalid={!!age.errors.age_visibility}
                                    value={age.data.age_visibility}
                                    onChange={(e) => age.setData('age_visibility', e.target.value)}
                                >
                                    {form.age.options.map((opt) => (
                                        <option key={opt.value} value={opt.value}>
                                            {t(opt.label)}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            <FormActions>
                                <Button type="submit" loading={age.processing}>
                                    {t('Save')}
                                </Button>
                            </FormActions>
                        </FormSection>
                    </form>
                </SectionCard>

                <SectionCard>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            locale.post('/locale');
                        }}
                    >
                        <FormSection title={t('Language')}>
                            <Field label={t('Language')} htmlFor="locale">
                                {/* Locale labels are language autonyms, rendered verbatim (not translation keys). */}
                                <Select
                                    id="locale"
                                    value={locale.data.locale}
                                    onChange={(e) => locale.setData('locale', e.target.value)}
                                >
                                    {form.locale.options.map((opt) => (
                                        <option key={opt.value} value={opt.value}>
                                            {opt.label}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            <FormActions>
                                <Button type="submit" loading={locale.processing}>
                                    {t('Save')}
                                </Button>
                            </FormActions>
                        </FormSection>
                    </form>
                </SectionCard>

                <SectionCard>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            surface.post('/m/member/config/surface');
                        }}
                    >
                        <FormSection title={t('Display')}>
                            <fieldset className="space-y-2">
                                {form.surface.options.map((opt) => (
                                    <RadioCard
                                        key={opt.value}
                                        name="preferred_surface"
                                        value={opt.value}
                                        checked={surface.data.preferred_surface === opt.value}
                                        onChange={(e) => surface.setData('preferred_surface', e.target.value)}
                                        label={t(opt.label)}
                                        description={opt.description ? t(opt.description) : undefined}
                                    />
                                ))}
                            </fieldset>
                            {surface.errors.preferred_surface && (
                                <p role="alert" className="text-xs text-destructive">
                                    {surface.errors.preferred_surface}
                                </p>
                            )}
                            <FormActions>
                                {/* Disabled until the choice differs from the current surface, so a casual save never pins. */}
                                <Button
                                    type="submit"
                                    loading={surface.processing}
                                    disabled={surface.data.preferred_surface === form.surface.value}
                                >
                                    {t('Save')}
                                </Button>
                            </FormActions>
                        </FormSection>
                    </form>
                </SectionCard>

                <SectionCard>
                    <FormSection
                        title={t('Appearance')}
                        description={t('Choose a light or dark look. Use system setting follows your device automatically.')}
                    >
                        <fieldset className="space-y-2">
                            {APPEARANCE_OPTIONS.map((opt) => (
                                <RadioCard
                                    key={opt.value}
                                    name="appearance"
                                    value={opt.value}
                                    checked={preference === opt.value}
                                    onChange={() => setColorMode(opt.value)}
                                    label={t(opt.label)}
                                />
                            ))}
                        </fieldset>
                    </FormSection>
                </SectionCard>

                <SectionCard>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            password.post('/m/member/config/password', { onSuccess: () => password.reset() });
                        }}
                    >
                        <FormSection title={t('Password')}>
                            <Field label={t('Current password')} htmlFor="current_password" error={password.errors.current_password}>
                                <Input
                                    id="current_password"
                                    type="password"
                                    autoComplete="current-password"
                                    aria-invalid={!!password.errors.current_password}
                                    value={password.data.current_password}
                                    onChange={(e) => password.setData('current_password', e.target.value)}
                                />
                            </Field>
                            <Field label={t('New password')} htmlFor="password" error={password.errors.password}>
                                <Input
                                    id="password"
                                    type="password"
                                    autoComplete="new-password"
                                    aria-invalid={!!password.errors.password}
                                    value={password.data.password}
                                    onChange={(e) => password.setData('password', e.target.value)}
                                />
                            </Field>
                            <Field label={t('New password (confirm)')} htmlFor="password_confirmation">
                                <Input
                                    id="password_confirmation"
                                    type="password"
                                    autoComplete="new-password"
                                    value={password.data.password_confirmation}
                                    onChange={(e) => password.setData('password_confirmation', e.target.value)}
                                />
                            </Field>
                            <FormActions>
                                <Button type="submit" loading={password.processing}>
                                    {t('Save')}
                                </Button>
                            </FormActions>
                        </FormSection>
                    </form>
                </SectionCard>

                <SectionCard>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            email.post('/m/member/config/email', { onSuccess: () => email.reset() });
                        }}
                    >
                        <FormSection title={t('Email address')} description={`${t('Current email address')}: ${form.email.value}`}>
                            <Field label={t('New email address')} htmlFor="new_email" error={email.errors.new_email}>
                                <Input
                                    id="new_email"
                                    type="email"
                                    aria-invalid={!!email.errors.new_email}
                                    value={email.data.new_email}
                                    onChange={(e) => email.setData('new_email', e.target.value)}
                                />
                            </Field>
                            <Field
                                label={t('Current password')}
                                htmlFor="email_password"
                                error={email.errors.password}
                                help={t('A confirmation link will be sent to the new address. The change takes effect once you open it.')}
                            >
                                <Input
                                    id="email_password"
                                    type="password"
                                    autoComplete="current-password"
                                    aria-invalid={!!email.errors.password}
                                    value={email.data.password}
                                    onChange={(e) => email.setData('password', e.target.value)}
                                />
                            </Field>
                            <FormActions>
                                <Button type="submit" loading={email.processing}>
                                    {t('Send confirmation')}
                                </Button>
                            </FormActions>
                        </FormSection>
                    </form>
                </SectionCard>

                <SectionCard>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            withdraw.post('/m/member/config/withdrawal');
                        }}
                    >
                        <FormSection
                            title={t('Account withdrawal')}
                            description={t('Withdrawing permanently deletes your account and cannot be undone.')}
                        >
                            <Field label={t('Current password')} htmlFor="withdraw_password" error={withdraw.errors.password}>
                                <Input
                                    id="withdraw_password"
                                    type="password"
                                    autoComplete="current-password"
                                    aria-invalid={!!withdraw.errors.password}
                                    value={withdraw.data.password}
                                    onChange={(e) => withdraw.setData('password', e.target.value)}
                                />
                            </Field>
                            <label className="flex items-center gap-2 text-sm text-foreground">
                                <Checkbox
                                    checked={withdraw.data.confirm}
                                    onChange={(e) => withdraw.setData('confirm', e.target.checked)}
                                />
                                {t('Yes, delete my account.')}
                            </label>
                            {withdraw.errors.confirm && (
                                <p role="alert" className="text-xs text-destructive">
                                    {withdraw.errors.confirm}
                                </p>
                            )}
                            <FormActions>
                                <Button type="submit" variant="destructive" loading={withdraw.processing}>
                                    {t('Withdraw from this site')}
                                </Button>
                            </FormActions>
                        </FormSection>
                    </form>
                </SectionCard>
            </main>
        </>
    );
}
