import { Head, useForm, usePage } from '@inertiajs/react';
import { Fragment } from 'react';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import { ProfileFieldInput } from './profile-field-input';
import type { ProfileForm } from './types';

interface EditProps extends PageProps {
    form: ProfileForm;
}

const BIRTHDAY_FIELD = 'op_preset_birthday';

/**
 * The age-visibility gate (member_preferences[age_visibility]), separate from the birthday
 * field's own visibility: that one governs the month/day, this one the age derived from the
 * year. Same compact trailing-select idiom as the per-field visibility controls.
 */
function AgeVisibilityBlock({
    age,
    value,
    onChange,
    error,
}: {
    age: NonNullable<ProfileForm['age']>;
    value: number;
    onChange: (v: number) => void;
    error?: string;
}) {
    const t = useT();

    return (
        <div className="py-5 last:pb-0">
            <div className="flex items-center justify-between gap-2">
                <label htmlFor="age_visibility" className="text-sm font-medium text-foreground">
                    {t('Who can see your age')}
                </label>
                <Select
                    id="age_visibility"
                    aria-describedby="age_visibility_help"
                    className="min-h-7 w-auto shrink-0 px-2 py-0.5 text-sm shadow-none"
                    value={value}
                    onChange={(e) => onChange(Number(e.target.value))}
                >
                    {age.options.map((opt) => (
                        <option key={opt.value} value={opt.value}>
                            {t(opt.label)}
                        </option>
                    ))}
                </Select>
            </div>
            <p id="age_visibility_help" className="mt-1 text-xs text-muted-foreground">
                {t('Applies to the age calculated from your birthday, separately from the birthday visibility.')}
            </p>
            {error && (
                <p role="alert" className="mt-1 text-xs text-destructive">
                    {error}
                </p>
            )}
        </div>
    );
}

export default function MemberEditProfile() {
    const t = useT();
    const { form } = usePage<EditProps>().props;

    const { data, setData, post, errors, processing } = useForm({
        name: form.name,
        profile: Object.fromEntries(form.fields.map((f) => [f.id, f.value])) as Record<number, string | string[]>,
        visibility: Object.fromEntries(
            form.fields.filter((f) => f.is_edit_public_flag).map((f) => [f.id, f.visibility]),
        ) as Record<number, number>,
        // Absent (undefined → not submitted) when the site has no birthday item. Submitted with the
        // whole form and always persisted server-side — the value shown is the value affirmed.
        age_visibility: form.age?.value,
    });

    const setProfile = (id: number, value: string | string[]) =>
        setData('profile', { ...data.profile, [id]: value });
    const setVisibility = (id: number, value: number) =>
        setData('visibility', { ...data.visibility, [id]: value });

    return (
        <>
            <Head title={t('Edit Profile')} />
            <h1 className="break-words text-xl font-semibold text-foreground">{t('Edit Profile')}</h1>

            <Panel>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        post('/m/member/edit/profile');
                    }}
                    className="space-y-5"
                >
                    {/* Hairline between field blocks so the trailing visibility control on each caption
                        row is unambiguously tied to the field it sits above (not the one below it). */}
                    <div className="divide-y divide-border">
                        <div className="pb-5">
                            <Field label={t('%nickname%')} htmlFor="member_name" required error={errors.name}>
                                <Input id="member_name" type="text" maxLength={255} required value={data.name} onChange={(e) => setData('name', e.target.value)} />
                            </Field>
                        </div>

                        {form.fields.map((field) => (
                            <Fragment key={field.id}>
                                <div className="py-5 last:pb-0">
                                    <ProfileFieldInput
                                        field={field}
                                        value={data.profile[field.id] ?? ''}
                                        onChange={(next) => setProfile(field.id, next)}
                                        error={(errors as Record<string, string>)[`profile.${field.id}`]}
                                        labelRight={
                                            field.is_edit_public_flag ? (
                                                // Subordinate to the caption: a compact min-height (≈ the label line, not
                                                // the 44px input target — 28px still clears WCAG 2.2 SC 2.5.8's 24px) plus
                                                // no shadow, so it doesn't outweigh or vertically drag the field name it
                                                // annotates. min-h (not a fixed height) lets it grow with zoom/text spacing.
                                                <Select
                                                    aria-label={`${field.caption} ${t('Visibility')}`}
                                                    className="min-h-7 w-auto shrink-0 px-2 py-0.5 text-sm shadow-none"
                                                    value={data.visibility[field.id]}
                                                    onChange={(e) => setVisibility(field.id, Number(e.target.value))}
                                                >
                                                    {field.visibilityOptions.map((opt) => (
                                                        <option key={opt.value} value={opt.value}>{t(opt.label)}</option>
                                                    ))}
                                                </Select>
                                            ) : undefined
                                        }
                                    />
                                </div>
                                {/* The age gate sits right under the birthday it derives from, so the two-gate
                                    model reads in place: the birthday control above governs the month/day, this
                                    one the derived age. */}
                                {field.name === BIRTHDAY_FIELD && form.age && (
                                    <AgeVisibilityBlock
                                        age={form.age}
                                        value={data.age_visibility ?? form.age.value}
                                        onChange={(v) => setData('age_visibility', v)}
                                        error={errors.age_visibility}
                                    />
                                )}
                            </Fragment>
                        ))}
                        {/* Fallback when the birthday item exists but is hidden from this form
                            (is_disp_config off): the age gate must stay reachable on Modern. */}
                        {form.age && !form.fields.some((f) => f.name === BIRTHDAY_FIELD) && (
                            <AgeVisibilityBlock
                                age={form.age}
                                value={data.age_visibility ?? form.age.value}
                                onChange={(v) => setData('age_visibility', v)}
                                error={errors.age_visibility}
                            />
                        )}
                    </div>

                    <Button type="submit" loading={processing}>{t('Update')}</Button>
                </form>
            </Panel>
        </>
    );
}
