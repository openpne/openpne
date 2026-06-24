import { Head, useForm, usePage } from '@inertiajs/react';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Option {
    value: string;
    label: string;
    description?: string;
}

interface ConfigForm {
    diary: { value: string; options: Option[] };
    age: { value: string; options: Option[] };
    locale: { value: string; options: Option[] };
    surface: { value: string; options: Option[] };
}

interface ConfigProps extends PageProps {
    form: ConfigForm;
}

export default function MemberConfig() {
    const t = useT();
    const { form, flash } = usePage<ConfigProps>().props;

    // One form per section so saving one never resubmits another (mirrors the Classic surface).
    const diary = useForm({ diary_default_visibility: form.diary.value });
    const age = useForm({ age_visibility: form.age.value });
    const locale = useForm({ locale: form.locale.value });
    const surface = useForm({ preferred_surface: form.surface.value });

    return (
        <>
            <Head title={t('Settings')} />
            <main className="mx-auto max-w-2xl space-y-8 px-4 py-8">
                <h1 className="text-xl font-semibold">{t('Settings')}</h1>

                {flash.status && <p role="status">{flash.status}</p>}

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        diary.post('/m/member/config/diary');
                    }}
                    className="space-y-2"
                >
                    <h2 className="font-semibold">{t('Diary')}</h2>
                    <label htmlFor="diary_default_visibility">{t('Default audience for new diaries')}</label>
                    <select
                        id="diary_default_visibility"
                        value={diary.data.diary_default_visibility}
                        onChange={(e) => diary.setData('diary_default_visibility', e.target.value)}
                    >
                        {form.diary.options.map((opt) => (
                            <option key={opt.value} value={opt.value}>{t(opt.label)}</option>
                        ))}
                    </select>
                    {diary.errors.diary_default_visibility && <p role="alert">{diary.errors.diary_default_visibility}</p>}
                    <button type="submit" disabled={diary.processing}>{t('Save')}</button>
                </form>

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        age.post('/m/member/config/age');
                    }}
                    className="space-y-2"
                >
                    <h2 className="font-semibold">{t('Age')}</h2>
                    <label htmlFor="age_visibility">{t('Who can see your age')}</label>
                    <select
                        id="age_visibility"
                        value={age.data.age_visibility}
                        onChange={(e) => age.setData('age_visibility', e.target.value)}
                    >
                        {form.age.options.map((opt) => (
                            <option key={opt.value} value={opt.value}>{t(opt.label)}</option>
                        ))}
                    </select>
                    {age.errors.age_visibility && <p role="alert">{age.errors.age_visibility}</p>}
                    <button type="submit" disabled={age.processing}>{t('Save')}</button>
                </form>

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        locale.post('/locale');
                    }}
                    className="space-y-2"
                >
                    <h2 className="font-semibold">{t('Language')}</h2>
                    <label htmlFor="locale">{t('Language')}</label>
                    {/* Locale labels are language autonyms, rendered verbatim (not translation keys). */}
                    <select
                        id="locale"
                        value={locale.data.locale}
                        onChange={(e) => locale.setData('locale', e.target.value)}
                    >
                        {form.locale.options.map((opt) => (
                            <option key={opt.value} value={opt.value}>{opt.label}</option>
                        ))}
                    </select>
                    <button type="submit" disabled={locale.processing}>{t('Save')}</button>
                </form>

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        surface.post('/m/member/config/surface');
                    }}
                    className="space-y-2"
                >
                    <h2 className="font-semibold">{t('Display')}</h2>
                    <fieldset className="space-y-1">
                        {form.surface.options.map((opt) => (
                            <label key={opt.value} className="flex items-start gap-2">
                                <input
                                    type="radio"
                                    name="preferred_surface"
                                    value={opt.value}
                                    checked={surface.data.preferred_surface === opt.value}
                                    onChange={(e) => surface.setData('preferred_surface', e.target.value)}
                                />
                                <span>
                                    <strong>{t(opt.label)}</strong>
                                    {opt.description && <span> — {t(opt.description)}</span>}
                                </span>
                            </label>
                        ))}
                    </fieldset>
                    {surface.errors.preferred_surface && <p role="alert">{surface.errors.preferred_surface}</p>}
                    {/* Disabled until the choice differs from the current surface, so a casual save never pins. */}
                    <button type="submit" disabled={surface.processing || surface.data.preferred_surface === form.surface.value}>{t('Save')}</button>
                </form>
            </main>
        </>
    );
}
