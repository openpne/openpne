import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { DiaryComment } from '../types';

interface DeleteProps extends PageProps {
    comment: DiaryComment;
    diaryId: number;
}

export default function DiaryCommentDelete() {
    const t = useT();
    const { comment, diaryId } = usePage<DeleteProps>().props;
    const { post, processing } = useForm({});

    return (
        <>
            <Head title={t('Delete the comment')} />
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8">
                <h1 className="text-2xl font-semibold">{t('Delete the comment')}</h1>

                <p>{t('Do you really want to delete this comment?')}</p>
                <blockquote className="whitespace-pre-wrap border-l-2 pl-3 text-muted-foreground">
                    {comment.body}
                </blockquote>

                <div className="flex gap-4">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            post(`/m/diary/comment/delete/${comment.id}`);
                        }}
                    >
                        <button type="submit" disabled={processing}>
                            {t('Delete')}
                        </button>
                    </form>
                    <Link href={`/m/diary/${diaryId}`}>{t('Cancel')}</Link>
                </div>
            </main>
        </>
    );
}
