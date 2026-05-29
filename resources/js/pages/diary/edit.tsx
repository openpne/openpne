import { Head, useForm, usePage } from '@inertiajs/react';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { DiaryDetail } from './types';

interface EditProps extends PageProps {
    diary: DiaryDetail;
}

export default function DiaryEdit() {
    const t = useT();
    const { diary, flash } = usePage<EditProps>().props;
    const { data, setData, post, errors, processing } = useForm({
        title: diary.title,
        body: diary.body,
        visibility: String(
            diary.visibility === 'private' ? 3 : diary.visibility === 'friends' ? 2 : 1,
        ),
    });

    return (
        <>
            <Head title={t('Edit %diary%')} />
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8">
                <h1 className="text-2xl font-semibold">{t('Edit %diary%')}</h1>

                {flash.error && <p role="alert">{flash.error}</p>}

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        post(`/m/diary/update/${diary.id}`);
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
                            <option value="1">{t('All members')}</option>
                            <option value="2">{t('%Friends% only')}</option>
                            <option value="3">{t('Private')}</option>
                        </select>
                        {errors.visibility && <p role="alert">{errors.visibility}</p>}
                    </div>
                    <button type="submit" disabled={processing}>
                        {t('Save')}
                    </button>
                </form>
            </main>
        </>
    );
}
