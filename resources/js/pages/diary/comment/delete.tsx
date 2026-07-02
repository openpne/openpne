import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
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
                <h1 className="text-xl font-semibold text-foreground">{t('Delete the comment')}</h1>

                <p className="text-foreground">{t('Do you really want to delete this comment?')}</p>
                <blockquote className="whitespace-pre-wrap border-l-2 border-border pl-3 text-muted-foreground">
                    {comment.body}
                </blockquote>

                <div className="flex items-center gap-4">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            post(`/m/diary/comment/delete/${comment.id}`);
                        }}
                    >
                        <Button type="submit" variant="destructive" loading={processing}>
                            {t('Delete')}
                        </Button>
                    </form>
                    <Link href={`/m/diary/${diaryId}`} className="text-sm text-link hover:underline">
                        {t('Cancel')}
                    </Link>
                </div>
            </main>
        </>
    );
}
