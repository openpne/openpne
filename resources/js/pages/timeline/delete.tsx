import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { TimelinePostEntry } from './types';

interface DeleteProps extends PageProps {
    post: TimelinePostEntry;
}

export default function TimelineDelete() {
    const t = useT();
    const { post: timelinePost } = usePage<DeleteProps>().props;
    const { post, processing } = useForm({});

    return (
        <>
            <Head title={t('Delete post')} />
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8">
                <h1 className="text-2xl font-semibold">{t('Delete post')}</h1>

                <p>{t('Delete this post?')}</p>

                <div className="flex gap-4">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            post(`/m/timeline/delete/${timelinePost.id}`);
                        }}
                    >
                        <button type="submit" disabled={processing}>
                            {t('Delete')}
                        </button>
                    </form>
                    <Link href={`/m/timeline/${timelinePost.id}`}>{t('Cancel')}</Link>
                </div>
            </main>
        </>
    );
}
