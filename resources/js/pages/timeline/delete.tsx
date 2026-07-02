import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
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
                <h1 className="text-xl font-semibold text-foreground">{t('Delete post')}</h1>

                <p className="text-foreground">{t('Delete this post?')}</p>

                <div className="flex items-center gap-4">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            post(`/m/timeline/delete/${timelinePost.id}`);
                        }}
                    >
                        <Button type="submit" variant="destructive" loading={processing}>
                            {t('Delete')}
                        </Button>
                    </form>
                    <Link href={`/m/timeline/${timelinePost.id}`} className="text-sm text-link hover:underline">
                        {t('Cancel')}
                    </Link>
                </div>
            </main>
        </>
    );
}
