import { Head, useForm, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Avatar } from '@/components/avatar';
import { Card } from '@/components/card';
import { ActionLink } from '@/components/ui/action-link';
import { Button } from '@/components/ui/button';
import { DangerLink } from '@/components/ui/danger-link';
import { FormActions, FormSection, RadioCardGroup } from '@/components/ui/field';
import { Heading } from '@/components/ui/heading';
import { RadioCard } from '@/components/ui/radio-card';
import { RadioPill } from '@/components/ui/radio-pill';
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
    // Absent while diaries are switched off — nothing is left for the default audience to apply to.
    diary?: { value: string; options: Option[] };
    email: { value: string };
    mfa: { enabled: boolean };
    locale: { value: string; options: Option[] };
    // Absent for a member the site neither offers AI accounts to nor has given one already.
    ai?: { count: number };
    // Absent under modern_only — the Classic/Modern picker is only served when Classic is available.
    surface?: { value: string; options: Option[] };
    // Absent while the site offers fewer than two looks; `current` is the stored choice's label
    // (null = following the site default), never the label of the look being rendered.
    look?: { current: string | null; default: string };
}

interface ConfigProps extends PageProps {
    form: ConfigForm;
}

function SettingsGroup({ title, danger = false, children }: { title: string; danger?: boolean; children: ReactNode }) {
    return (
        <section className="space-y-3">
            <Heading as="h2" variant="group" className={danger ? 'text-destructive' : undefined}>
                {title}
            </Heading>
            <Card className={danger ? 'border-destructive/40' : undefined}>
                <div className="divide-y divide-border px-4 sm:px-6">{children}</div>
            </Card>
        </section>
    );
}

function GroupItem({ children }: { children: ReactNode }) {
    return <div className="py-5">{children}</div>;
}

function DetailRow({ title, value, action }: { title: string; value?: ReactNode; action: ReactNode }) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
            <div className="space-y-0.5">
                {/* Still an h3 — a settings page is worth navigating by heading — but styled as the
                    row's content line. */}
                <h3 className="text-base text-foreground">{title}</h3>
                {value && <p className="text-sm text-muted-foreground">{value}</p>}
            </div>
            {action}
        </div>
    );
}

/**
 * Always present, with reserved height, so the aria-live region exists before the announcement and
 * the layout never shifts.
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

    // One form per preference so saving one never resubmits another; the hooks run unconditionally,
    // and a fallback is inert because each optional section renders only when its key is present.
    const diary = useForm({ diary_default_visibility: form.diary?.value ?? '' });
    const locale = useForm({ locale: form.locale.value });
    const surface = useForm({ preferred_surface: form.surface?.value ?? '' });
    // Appearance is a client-side display preference (localStorage), applied immediately — no server post.
    const { preference, set: setColorMode } = useColorMode();
    // Const so the truthiness narrowing holds inside the options .map closures below.
    const diaryField = form.diary;
    const surfaceField = form.surface;
    const lookField = form.look;

    // Preference radios apply on selection (no per-section save button); SavedIndicator is the
    // per-control feedback that replaces the page flash, which the server omits for these on Modern.
    const saveDiary = (value: string) => {
        diary.setData('diary_default_visibility', value);
        diary.post('/member/config/diary', { preserveScroll: true });
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
            {auth.user && (
                <SettingsGroup title={t('Profile')}>
                    <GroupItem>
                        <DetailRow
                            title={t('Profile')}
                            value={auth.user.name}
                            action={
                                <ActionLink href="/member/edit/profile" variant="outline" size="sm">
                                    {t('Edit')}
                                </ActionLink>
                            }
                        />
                    </GroupItem>
                    <GroupItem>
                        <DetailRow
                            title={t('Profile image')}
                            value={<Avatar id={auth.user.id} name={auth.user.name} src={auth.user.imageUrl} color={auth.user.avatarColor} isAi={auth.user.isAi} size="sm" decorative />}
                            action={
                                <ActionLink href="/member/avatar" variant="outline" size="sm">
                                    {t('Change')}
                                </ActionLink>
                            }
                        />
                    </GroupItem>
                </SettingsGroup>
            )}

            {/* Privacy holds the diary default alone, so the whole group goes with it. */}
            {diaryField && (
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
                                    {diaryField.options.map((opt) => (
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
            )}

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
                                surface.post('/member/config/surface');
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
                                        shell, so it must not fire on a stray radio click. */}
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

                {/* A row, not a picker: what separates the looks takes a table to state, so the
                    choice lives on its own page and this says only which one is in force. */}
                {lookField && (
                    <GroupItem>
                        <DetailRow
                            title={t('Layout')}
                            value={
                                lookField.current
                                    ? t(lookField.current)
                                    : t('Match the site default (currently :look)', { look: t(lookField.default) })
                            }
                            action={
                                <ActionLink href="/member/config/look" variant="outline" size="sm">
                                    {t('Change')}
                                </ActionLink>
                            }
                        />
                    </GroupItem>
                )}
            </SettingsGroup>

            <SettingsGroup title={t('Notifications')}>
                <GroupItem>
                    <DetailRow
                        title={t('Notifications')}
                        value={t('Choose which notifications you receive, in-app, by email, and by push.')}
                        action={
                            <ActionLink href="/member/config/notifications" variant="outline" size="sm">
                                {t('Edit')}
                            </ActionLink>
                        }
                    />
                </GroupItem>
            </SettingsGroup>

            {form.ai && (
                <SettingsGroup title={t('AI accounts')}>
                    <GroupItem>
                        <DetailRow
                            title={t('AI accounts')}
                            value={form.ai.count > 0 ? t(':count in use', { count: form.ai.count }) : t('None yet')}
                            action={
                                <ActionLink href="/member/config/ai" variant="outline" size="sm">
                                    {t('Manage')}
                                </ActionLink>
                            }
                        />
                    </GroupItem>
                </SettingsGroup>
            )}

            {/* Consequential account changes are rows into dedicated detail pages, keeping this page
                a scannable hub. */}
            <SettingsGroup title={t('Account')}>
                <GroupItem>
                    <DetailRow
                        title={t('Email address')}
                        value={form.email.value}
                        action={
                            <ActionLink href="/member/config/email" variant="outline" size="sm">
                                {t('Change')}
                            </ActionLink>
                        }
                    />
                </GroupItem>
                <GroupItem>
                    <DetailRow
                        title={t('Password')}
                        action={
                            <ActionLink href="/member/config/password" variant="outline" size="sm">
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
                            <ActionLink href="/member/config/mfa" variant="outline" size="sm">
                                {t('Manage')}
                            </ActionLink>
                        }
                    />
                </GroupItem>
            </SettingsGroup>

            {/* Classic reaches these from its footer; Modern has no site-wide footer, so this is a
                member's way in. */}
            <SettingsGroup title={t('About this site')}>
                <GroupItem>
                    <DetailRow
                        title={t('Terms of service')}
                        action={
                            <ActionLink href="/terms" variant="outline" size="sm">
                                {t('Read')}
                            </ActionLink>
                        }
                    />
                </GroupItem>
                <GroupItem>
                    <DetailRow
                        title={t('Privacy policy')}
                        action={
                            <ActionLink href="/privacy" variant="outline" size="sm">
                                {t('Read')}
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
                        <DangerLink href="/member/config/withdrawal">{t('Proceed to withdrawal')}</DangerLink>
                    </div>
                </GroupItem>
            </SettingsGroup>
        </>
    );
}
