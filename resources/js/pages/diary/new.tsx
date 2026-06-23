import { Head, useForm, usePage } from '@inertiajs/react';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';

type VisibilityOption = { value: string; label: string };

export default function DiaryNew({
    defaultVisibility,
    visibilityOptions,
}: {
    defaultVisibility: string;
    visibilityOptions: VisibilityOption[];
}) {
    const t = useT();
    const { flash } = usePage<PageProps>().props;
    const { data, setData, post, errors, processing } = useForm({
        title: '',
        body: '',
        visibility: defaultVisibility,
        images: [] as File[],
    });

    return (
        <>
            <Head title={t('Write a %diary%')} />
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8">
                <h1 className="text-2xl font-semibold">{t('Write a %diary%')}</h1>

                {flash.error && <p role="alert">{flash.error}</p>}

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        // forceFormData: the upload needs a multipart body, which Inertia uses
                        // automatically once a File is present but not for an initially-empty array.
                        post('/m/diary/create', { forceFormData: true });
                    }}
                    className="space-y-4"
                >
                    <div>
                        <label htmlFor="diary_title">{t('Title')}</label>
                        <input
                            id="diary_title"
                            type="text"
                            value={data.title}
                            onChange={(e) => setData('title', e.target.value)}
                            required
                        />
                        {errors.title && <p role="alert">{errors.title}</p>}
                    </div>
                    <div>
                        <label htmlFor="diary_body">{t('Body')}</label>
                        <textarea
                            id="diary_body"
                            value={data.body}
                            onChange={(e) => setData('body', e.target.value)}
                            required
                            rows={10}
                        />
                        {errors.body && <p role="alert">{errors.body}</p>}
                    </div>
                    <div>
                        <label htmlFor="diary_visibility">{t('Visibility')}</label>
                        <select
                            id="diary_visibility"
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
                        <label htmlFor="diary_images">{t('Images')}</label>
                        <input
                            id="diary_images"
                            type="file"
                            accept="image/jpeg,image/png,image/gif,image/webp"
                            multiple
                            onChange={(e) => setData('images', Array.from(e.target.files ?? []).slice(0, 3))}
                        />
                        {errors.images && <p role="alert">{errors.images}</p>}
                    </div>
                    <button type="submit" disabled={processing}>
                        {t('Post')}
                    </button>
                </form>
            </main>
        </>
    );
}
