import { Head, useForm, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Avatar } from '@/components/avatar';
import { Card } from '@/components/card';
import { ActionLink } from '@/components/ui/action-link';
import { Button } from '@/components/ui/button';
import { DangerLink } from '@/components/ui/danger-link';
import { FormActions, FormSection, RadioCardGroup } from '@/components/ui/field';
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
    email: { value: string };
    mfa: { enabled: boolean };
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
 * A consequential setting kept as a compact row: title (+ current value) with a link to the
 * dedicated detail page that carries the actual form.
 */
function DetailRow({ title, value, action }: { title: string; value?: ReactNode; action: ReactNode }) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
            <div className="space-y-0.5">
                <h3 className="text-base font-semibold text-foreground">{title}</h3>
                {value && <p className="text-sm text-muted-foreground">{value}</p>}
            </div>
            {action}
        </div>
    );
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
    const { form, auth } = usePage<ConfigProps>().props;

    // One form per preference so saving one never resubmits another (mirrors the Classic surface).
    const diary = useForm({ diary_default_visibility: form.diary.value });
    const locale = useForm({ locale: form.locale.value });
    // Hooks run unconditionally; the fallback is inert since the surface section renders only when
    // form.surface is present (Classic available).
    const surface = useForm({ preferred_surface: form.surface?.value ?? '' });
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
    // The locale switch responds with a hard navigation (the page reloading in the chosen language
    // is the feedback), so no SavedIndicator here.
    const switchLocale = (value: string) => {
        locale.setData('locale', value);
        locale.post('/locale');
    };

    return (
        <>
            <Head title={t('Settings')} />
            {/* Identity leads (the survey convention): link rows into the profile-edit pages,
                which also host the age-visibility gate that used to live on this page. */}
            {auth.user && (
                <SettingsGroup title={t('Profile')}>
                    <GroupItem>
                        <DetailRow
                            title={t('Profile')}
                            value={auth.user.name}
                            action={
                                <ActionLink href="/m/member/edit/profile" variant="outline" size="sm">
                                    {t('Edit')}
                                </ActionLink>
                            }
                        />
                    </GroupItem>
                    <GroupItem>
                        <DetailRow
                            title={t('Profile image')}
                            value={<Avatar id={auth.user.id} name={auth.user.name} src={auth.user.imageUrl} color={auth.user.avatarColor} size="sm" decorative />}
                            action={
                                <ActionLink href="/m/member/avatar" variant="outline" size="sm">
                                    {t('Change')}
                                </ActionLink>
                            }
                        />
                    </GroupItem>
                </SettingsGroup>
            )}

            <SettingsGroup title={t('Privacy')}>
                <GroupItem>
                    <FormSection
                        title={t('Default audience for new %diaries%')}
                        headingLevel="h3"
                        description={t('Applies to %diaries% you write from now on. Existing %diaries% keep their audience.')}
                    >
                        <RadioCardGroup
                            legend={t('Default audience for new %diaries%')}
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
                {/* Age visibility is edited next to the birthday it derives from, on the profile-edit page. */}
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

            <SettingsGroup title={t('Notifications')}>
                <GroupItem>
                    <DetailRow
                        title={t('Notifications')}
                        value={t('Choose which notifications you receive, in-app and by email.')}
                        action={
                            <ActionLink href="/m/member/config/notifications" variant="outline" size="sm">
                                {t('Edit')}
                            </ActionLink>
                        }
                    />
                </GroupItem>
            </SettingsGroup>

            {/* Consequential account changes are rows into dedicated detail pages: the forms are
                deliberately one level deeper (focused page, visible validation errors, weight
                matching the action), keeping this page a scannable hub. */}
            <SettingsGroup title={t('Account')}>
                <GroupItem>
                    <DetailRow
                        title={t('Email address')}
                        value={form.email.value}
                        action={
                            <ActionLink href="/m/member/config/email" variant="outline" size="sm">
                                {t('Change')}
                            </ActionLink>
                        }
                    />
                </GroupItem>
                <GroupItem>
                    <DetailRow
                        title={t('Password')}
                        action={
                            <ActionLink href="/m/member/config/password" variant="outline" size="sm">
                                {t('Change')}
                            </ActionLink>
                        }
                    />
                </GroupItem>
                <GroupItem>
                    <DetailRow
                        title={t('Two-factor authentication')}
                        value={form.mfa.enabled ? t('Enabled') : t('Not enabled')}
                        action={
                            <ActionLink href="/m/member/config/mfa" variant="outline" size="sm">
                                {t('Manage')}
                            </ActionLink>
                        }
                    />
                </GroupItem>
            </SettingsGroup>

            <SettingsGroup title={t('Account withdrawal')} danger>
                <GroupItem>
                    <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                        <p className="text-sm text-muted-foreground">
                            {t('Withdrawing permanently deletes your account and cannot be undone.')}
                        </p>
                        <DangerLink href="/m/member/config/withdrawal">{t('Proceed to withdrawal')}</DangerLink>
                    </div>
                </GroupItem>
            </SettingsGroup>
        </>
    );
}
