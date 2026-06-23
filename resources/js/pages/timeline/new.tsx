import { Head, useForm, usePage } from '@inertiajs/react';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';

type VisibilityOption = { value: string; label: string };

export default function TimelineNew({
    defaultVisibility,
    visibilityOptions,
}: {
    defaultVisibility: string;
    visibilityOptions: VisibilityOption[];
}) {
    const t = useT();
    const { flash } = usePage<PageProps>().props;
    const { data, setData, post, errors, processing } = useForm({
        body: '',
        visibility: defaultVisibility,
        image: null as File | null,
    });

    return (
        <>
            <Head title={t('%Post_activity%')} />
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8">
                <h1 className="text-2xl font-semibold">{t('%Post_activity%')}</h1>

                {flash.error && <p role="alert">{flash.error}</p>}

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        // forceFormData: the upload needs a multipart body, which Inertia uses
                        // automatically once a File is present but not for an initially-null field.
                        post('/m/timeline/create', { forceFormData: true });
                    }}
                    className="space-y-4"
                >
                    <div>
                        <label htmlFor="timeline_body">{t('Body')}</label>
                        <textarea
                            id="timeline_body"
                            value={data.body}
                            onChange={(e) => setData('body', e.target.value)}
                            required
                            maxLength={140}
                            rows={4}
                        />
                        {errors.body && <p role="alert">{errors.body}</p>}
                    </div>
                    <div>
                        <label htmlFor="timeline_visibility">{t('Visibility')}</label>
                        <select
                            id="timeline_visibility"
                            value={data.visibility}
                            onChange={(e) => setData('visibility', e.target.value)}
                        >
                            {visibilityOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {t(option.label)}
                                </option>
                            ))}
                        </select>
                        {errors.visibility && <p role="alert">{errors.visibility}</p>}
                    </div>
                    <div>
                        <label htmlFor="timeline_image">{t('Image')}</label>
                        <input
                            id="timeline_image"
                            type="file"
                            accept="image/jpeg,image/png,image/gif,image/webp"
                            onChange={(e) => setData('image', e.target.files?.[0] ?? null)}
                        />
                        {errors.image && <p role="alert">{errors.image}</p>}
                    </div>
                    <button type="submit" disabled={processing}>
                        {t('%Post_activity%')}
                    </button>
                </form>
            </main>
        </>
    );
}
