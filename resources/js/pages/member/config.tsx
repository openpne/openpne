import { Head, useForm, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Card } from '@/components/card';
import { FlashMessage } from '@/components/flash-message';
import { Button } from '@/components/ui/button';
import { CheckboxField, Field, FormActions, FormSection, RadioCardGroup } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { RadioCard } from '@/components/ui/radio-card';
import { RadioPill } from '@/components/ui/radio-pill';
import { type ColorMode, useColorMode } from '@/lib/color-mode';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
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
    // Absent under modern_only — the Classic/Modern picker is only served when Classic is available.
    surface?: { value: string; options: Option[] };
}

interface ConfigProps extends PageProps {
    form: ConfigForm;
}

/** A titled group of related sections: one h2 over one card, sections separated by dividers. */
function SettingsGroup({ title, danger = false, children }: { title: string; danger?: boolean; children: ReactNode }) {
    return (
        <section className="space-y-3">
            <h2 className={cn('text-lg font-semibold', danger ? 'text-destructive' : 'text-foreground')}>{title}</h2>
            <Card className={danger ? 'border-destructive/40' : undefined}>
                <div className="divide-y divide-border px-6">{children}</div>
            </Card>
        </section>
    );
}

function GroupItem({ children }: { children: ReactNode }) {
    return <div className="py-5">{children}</div>;
}

/**
 * Inline feedback for instant-apply preferences. The element is always present (with reserved
 * height) so the aria-live region exists before the announcement and the layout never shifts.
 */
function SavedIndicator({ show }: { show: boolean }) {
    const t = useT();

    return (
        <p aria-live="polite" className="min-h-5 text-sm text-muted-foreground">
            {show ? `✓ ${t('Saved')}` : null}
        </p>
    );
}

export default function MemberConfig() {
    const t = useT();
    const { form, flash } = usePage<ConfigProps>().props;

    // One form per section so saving one never resubmits another (mirrors the Classic surface).
    const diary = useForm({ diary_default_visibility: form.diary.value });
    const age = useForm({ age_visibility: form.age.value });
    const locale = useForm({ locale: form.locale.value });
    // Hooks run unconditionally; the fallback is inert since the surface section renders only when
    // form.surface is present (Classic available).
    const surface = useForm({ preferred_surface: form.surface?.value ?? '' });
    const password = useForm({ current_password: '', password: '', password_confirmation: '' });
    const email = useForm({ new_email: '', password: '' });
    const withdraw = useForm({ password: '', confirm: false });
    // Appearance is a client-side display preference (localStorage), applied immediately — no server post.
    const { preference, set: setColorMode } = useColorMode();
    // Const so the truthiness narrowing holds inside the options .map closure below.
    const surfaceField = form.surface;

    // Preference radios apply on selection (no per-section save button); SavedIndicator is the
    // per-control feedback that replaces the page flash, which the server omits for these on Modern.
    const saveDiary = (value: string) => {
        diary.setData('diary_default_visibility', value);
        diary.post('/m/member/config/diary', { preserveScroll: true });
    };
    const saveAge = (value: string) => {
        age.setData('age_visibility', value);
        age.post('/m/member/config/age', { preserveScroll: true });
    };
    // The locale switch responds with a hard navigation (the page reloading in the chosen language
    // is the feedback), so no SavedIndicator here.
    const switchLocale = (value: string) => {
        locale.setData('locale', value);
        locale.post('/locale');
    };

    return (
        <>
            <Head title={t('Settings')} />
            <main className="mx-auto max-w-2xl space-y-8 px-4 py-8">
                <h1 className="text-xl font-semibold text-foreground">{t('Settings')}</h1>

                {flash.status && <FlashMessage>{flash.status}</FlashMessage>}
                {flash.error && <FlashMessage variant="error">{flash.error}</FlashMessage>}

                <SettingsGroup title={t('Privacy')}>
                    <GroupItem>
                        <FormSection title={t('Default audience for new diaries')} headingLevel="h3">
                            <RadioCardGroup
                                legend={t('Default audience for new diaries')}
                                error={diary.errors.diary_default_visibility}
                            >
                                <div className="flex flex-wrap gap-2">
                                    {form.diary.options.map((opt) => (
                                        <RadioPill
                                            key={opt.value}
                                            name="diary_default_visibility"
                                            value={opt.value}
                                            checked={diary.data.diary_default_visibility === opt.value}
                                            disabled={diary.processing}
                                            onChange={(e) => saveDiary(e.target.value)}
                                            label={t(opt.label)}
                                        />
                                    ))}
                                </div>
                            </RadioCardGroup>
                            <SavedIndicator show={diary.recentlySuccessful} />
                        </FormSection>
                    </GroupItem>

                    <GroupItem>
                        <FormSection title={t('Who can see your age')} headingLevel="h3">
                            <RadioCardGroup legend={t('Who can see your age')} error={age.errors.age_visibility}>
                                <div className="flex flex-wrap gap-2">
                                    {form.age.options.map((opt) => (
                                        <RadioPill
                                            key={opt.value}
                                            name="age_visibility"
                                            value={opt.value}
                                            checked={age.data.age_visibility === opt.value}
                                            disabled={age.processing}
                                            onChange={(e) => saveAge(e.target.value)}
                                            label={t(opt.label)}
                                        />
                                    ))}
                                </div>
                            </RadioCardGroup>
                            <SavedIndicator show={age.recentlySuccessful} />
                        </FormSection>
                    </GroupItem>
                </SettingsGroup>

                <SettingsGroup title={t('Display & language')}>
                    <GroupItem>
                        <FormSection
                            title={t('Appearance')}
                            headingLevel="h3"
                            description={t('Choose a light or dark look. Use system setting follows your device automatically.')}
                        >
                            <RadioCardGroup legend={t('Appearance')}>
                                <div className="flex flex-wrap gap-2">
                                    {APPEARANCE_OPTIONS.map((opt) => (
                                        <RadioPill
                                            key={opt.value}
                                            name="appearance"
                                            value={opt.value}
                                            checked={preference === opt.value}
                                            onChange={() => setColorMode(opt.value)}
                                            label={t(opt.label)}
                                        />
                                    ))}
                                </div>
                            </RadioCardGroup>
                        </FormSection>
                    </GroupItem>

                    <GroupItem>
                        <FormSection title={t('Language')} headingLevel="h3">
                            {/* Locale labels are language autonyms, rendered verbatim (not translation keys). */}
                            <RadioCardGroup legend={t('Language')}>
                                <div className="flex flex-wrap gap-2">
                                    {form.locale.options.map((opt) => (
                                        <RadioPill
                                            key={opt.value}
                                            name="locale"
                                            value={opt.value}
                                            checked={locale.data.locale === opt.value}
                                            disabled={locale.processing}
                                            onChange={(e) => switchLocale(e.target.value)}
                                            // lang belongs on the visible autonym text (RadioPill spreads rest props onto the input).
                                            label={<span lang={opt.value}>{opt.label}</span>}
                                        />
                                    ))}
                                </div>
                            </RadioCardGroup>
                        </FormSection>
                    </GroupItem>

                    {surfaceField && (
                        <GroupItem>
                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    surface.post('/m/member/config/surface');
                                }}
                            >
                                <FormSection title={t('Display')} headingLevel="h3">
                                    <RadioCardGroup legend={t('Display')} error={surface.errors.preferred_surface}>
                                        {surfaceField.options.map((opt) => (
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
                                    </RadioCardGroup>
                                    <FormActions>
                                        {/* The one explicit button among the preferences: switching re-renders the whole
                                            shell on the chosen surface, so it must not fire on a stray radio click.
                                            Disabled until the choice differs from the current surface, so a casual save
                                            never pins. */}
                                        <Button
                                            type="submit"
                                            loading={surface.processing}
                                            disabled={surface.data.preferred_surface === surfaceField.value}
                                        >
                                            {t('Switch')}
                                        </Button>
                                    </FormActions>
                                </FormSection>
                            </form>
                        </GroupItem>
                    )}
                </SettingsGroup>

                <SettingsGroup title={t('Account')}>
                    <GroupItem>
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                email.post('/m/member/config/email', { onSuccess: () => email.reset() });
                            }}
                        >
                            <FormSection
                                title={t('Email address')}
                                headingLevel="h3"
                                description={`${t('Current email address')}: ${form.email.value}`}
                            >
                                <Field label={t('New email address')} htmlFor="new_email" error={email.errors.new_email}>
                                    <Input
                                        id="new_email"
                                        type="email"
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
                    </GroupItem>

                    <GroupItem>
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                password.post('/m/member/config/password', { onSuccess: () => password.reset() });
                            }}
                        >
                            <FormSection title={t('Password')} headingLevel="h3">
                                <Field label={t('Current password')} htmlFor="current_password" error={password.errors.current_password}>
                                    <Input
                                        id="current_password"
                                        type="password"
                                        autoComplete="current-password"
                                        value={password.data.current_password}
                                        onChange={(e) => password.setData('current_password', e.target.value)}
                                    />
                                </Field>
                                <Field label={t('New password')} htmlFor="password" error={password.errors.password}>
                                    <Input
                                        id="password"
                                        type="password"
                                        autoComplete="new-password"
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
                    </GroupItem>
                </SettingsGroup>

                {/* Danger zone: the irreversible action sits last, visually separated from everything above. */}
                <SettingsGroup title={t('Account withdrawal')} danger>
                    <GroupItem>
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                withdraw.post('/m/member/config/withdrawal');
                            }}
                        >
                            <div className="space-y-4">
                                <p className="text-sm text-muted-foreground">
                                    {t('Withdrawing permanently deletes your account and cannot be undone.')}
                                </p>
                                <Field label={t('Current password')} htmlFor="withdraw_password" error={withdraw.errors.password}>
                                    <Input
                                        id="withdraw_password"
                                        type="password"
                                        autoComplete="current-password"
                                        value={withdraw.data.password}
                                        onChange={(e) => withdraw.setData('password', e.target.value)}
                                    />
                                </Field>
                                <CheckboxField
                                    label={t('Yes, delete my account.')}
                                    checked={withdraw.data.confirm}
                                    onChange={(e) => withdraw.setData('confirm', e.target.checked)}
                                    error={withdraw.errors.confirm}
                                />
                                <FormActions>
                                    <Button type="submit" variant="destructive" loading={withdraw.processing}>
                                        {t('Withdraw from this site')}
                                    </Button>
                                </FormActions>
                            </div>
                        </form>
                    </GroupItem>
                </SettingsGroup>
            </main>
        </>
    );
}
