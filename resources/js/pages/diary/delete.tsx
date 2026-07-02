import { Head, useForm, usePage } from '@inertiajs/react';
import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { DiarySummary } from './types';

interface DeleteProps extends PageProps {
    diary: DiarySummary;
}

export default function DiaryDelete() {
    const t = useT();
    const { diary } = usePage<DeleteProps>().props;
    const { post, processing } = useForm({});

    return (
        <>
            <Head title={t('Delete %diary%')} />
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8">
                <h1 className="text-xl font-semibold text-foreground">{t('Delete %diary%')}</h1>

                <p className="text-foreground">{t('Delete ":title"?', { title: diary.title })}</p>

                <div className="flex items-center gap-4">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            post(`/m/diary/delete/${diary.id}`);
                        }}
                    >
                        <Button type="submit" variant="destructive" loading={processing}>
                            {t('Delete')}
                        </Button>
                    </form>
                    <Link href={`/m/diary/${diary.id}`} className="text-sm text-link hover:underline">
                        {t('Cancel')}
                    </Link>
                </div>
            </main>
        </>
    );
}
