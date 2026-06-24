import { Head, useForm, usePage } from '@inertiajs/react';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Option {
    value: string;
    label: string;
}

interface ConfigForm {
    diary: { value: string; options: Option[] };
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
                    <button type="submit" disabled={locale.processing}>{t('Change')}</button>
                </form>

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        surface.post('/m/member/config/surface');
                    }}
                    className="space-y-2"
                >
                    <h2 className="font-semibold">{t('Display')}</h2>
                    <label htmlFor="preferred_surface">{t('Interface')}</label>
                    <select
                        id="preferred_surface"
                        value={surface.data.preferred_surface}
                        onChange={(e) => surface.setData('preferred_surface', e.target.value)}
                    >
                        {form.surface.options.map((opt) => (
                            <option key={opt.value} value={opt.value}>{t(opt.label)}</option>
                        ))}
                    </select>
                    {surface.errors.preferred_surface && <p role="alert">{surface.errors.preferred_surface}</p>}
                    <button type="submit" disabled={surface.processing}>{t('Save')}</button>
                </form>
            </main>
        </>
    );
}
