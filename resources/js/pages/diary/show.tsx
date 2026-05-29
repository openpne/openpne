import { Head, Link, usePage } from '@inertiajs/react';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { DiaryDetail } from './types';

interface ShowProps extends PageProps {
    diary: DiaryDetail;
}

export default function DiaryShow() {
    const t = useT();
    const { diary, flash, auth } = usePage<ShowProps>().props;
    const isOwner = auth.user?.id === diary.author.id;

    return (
        <>
            <Head title={diary.title} />
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8">
                <h1 className="text-2xl font-semibold">{diary.title}</h1>

                {flash.status && <p role="status">{flash.status}</p>}
                {flash.error && <p role="alert">{flash.error}</p>}

                <p className="text-sm text-muted-foreground">
                    {diary.author.name} &mdash; {new Date(diary.createdAt).toLocaleString()}
                </p>

                <div className="whitespace-pre-wrap">{diary.body}</div>

                {isOwner && (
                    <div className="flex gap-4 text-sm">
                        <Link href={`/m/diary/edit/${diary.id}`} className="hover:underline">
                            {t('Edit')}
                        </Link>
                        <Link href={`/m/diary/deleteConfirm/${diary.id}`} className="hover:underline">
                            {t('Delete')}
                        </Link>
                    </div>
                )}
            </main>
        </>
    );
}
