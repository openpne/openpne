import { Head, Link, usePage } from '@inertiajs/react';
import { Pagination } from '@/components/pagination';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { PaginatedTimelinePosts, TimelinePostAuthor } from './types';

interface MemberProps extends PageProps {
    owner: TimelinePostAuthor;
    isOwner: boolean;
    posts: PaginatedTimelinePosts;
}

export default function TimelineMember() {
    const t = useT();
    const { owner, isOwner, posts, flash } = usePage<MemberProps>().props;
    const title = isOwner ? t('%Activity%') : t(":name's %activity%", { name: owner.name });

    return (
        <>
            <Head title={title} />
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8">
                <h1 className="text-2xl font-semibold">{title}</h1>

                {isOwner && (
                    <Link href="/m/timeline/new" className="hover:underline">
                        {t('%Post_activity%')}
                    </Link>
                )}

                {flash.status && <p role="status">{flash.status}</p>}
                {flash.error && <p role="alert">{flash.error}</p>}

                {posts.data.length === 0 ? (
                    <p>{t('No %activity% posts to show.')}</p>
                ) : (
                    <>
                        <ul className="space-y-4">
                            {posts.data.map((post) => (
                                <li key={post.id} className="space-y-2 border-b pb-4">
                                    <div className="flex items-center justify-between text-sm">
                                        <Link href={`/m/member/${post.author.id}/timeline`} className="font-medium hover:underline">
                                            {post.author.name}
                                        </Link>
                                        <Link href={`/m/timeline/${post.id}`} className="text-muted-foreground hover:underline">
                                            {new Date(post.createdAt).toLocaleString()}
                                        </Link>
                                    </div>
                                    <p className="whitespace-pre-wrap">{post.body}</p>
                                    {post.images.length > 0 && (
                                        <div className="flex flex-wrap gap-2">
                                            {post.images.map((image) => (
                                                <img key={image.id} src={image.thumbnailUrl} alt="" className="rounded" />
                                            ))}
                                        </div>
                                    )}
                                    {isOwner && (
                                        <Link href={`/m/timeline/deleteConfirm/${post.id}`} className="text-sm hover:underline">
                                            {t('Delete')}
                                        </Link>
                                    )}
                                </li>
                            ))}
                        </ul>
                        <Pagination meta={posts.meta} />
                    </>
                )}
            </main>
        </>
    );
}
