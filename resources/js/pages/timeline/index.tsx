import { Head, Link, usePage } from '@inertiajs/react';
import { Pagination } from '@/components/pagination';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import { TimelinePostCard } from './post-card';
import type { PaginatedTimelinePosts } from './types';

interface IndexProps extends PageProps {
    viewerId: number;
    posts: PaginatedTimelinePosts;
}

export default function TimelineIndex() {
    const t = useT();
    const { viewerId, posts, flash } = usePage<IndexProps>().props;
    const title = t('%Activity%');

    return (
        <>
            <Head title={title} />
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8">
                <h1 className="text-2xl font-semibold">{title}</h1>

                <Link href="/m/timeline/new" className="hover:underline">
                    {t('%Post_activity%')}
                </Link>

                {flash.status && <p role="status">{flash.status}</p>}
                {flash.error && <p role="alert">{flash.error}</p>}

                {posts.data.length === 0 ? (
                    <p>{t('No %activity% posts to show.')}</p>
                ) : (
                    <>
                        <ul className="space-y-4">
                            {posts.data.map((post) => (
                                <TimelinePostCard key={post.id} post={post} viewerId={viewerId} />
                            ))}
                        </ul>
                        <Pagination meta={posts.meta} />
                    </>
                )}
            </main>
        </>
    );
}
