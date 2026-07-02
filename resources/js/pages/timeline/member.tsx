import { Head, usePage } from '@inertiajs/react';
import { Pagination } from '@/components/pagination';
import { ActionLink } from '@/components/ui/action-link';
import { FlashMessage } from '@/components/flash-message';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import { TimelinePostCard } from './post-card';
import type { PaginatedTimelinePosts, TimelinePostAuthor } from './types';

interface MemberProps extends PageProps {
    owner: TimelinePostAuthor;
    isOwner: boolean;
    viewerId: number;
    posts: PaginatedTimelinePosts;
}

export default function TimelineMember() {
    const t = useT();
    const { owner, isOwner, viewerId, posts, flash } = usePage<MemberProps>().props;
    const title = isOwner ? t('%Activity%') : t(":name's %activity%", { name: owner.name });

    return (
        <>
            <Head title={title} />
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8">
                <h1 className="text-xl font-semibold text-foreground">{title}</h1>

                {isOwner && (
                    <div>
                        <ActionLink href="/m/timeline/new">{t('%Post_activity%')}</ActionLink>
                    </div>
                )}

                {flash.status && <FlashMessage>{flash.status}</FlashMessage>}
                {flash.error && <FlashMessage variant="error">{flash.error}</FlashMessage>}

                {posts.data.length === 0 ? (
                    <p className="text-sm text-muted-foreground">{t('No %activity% posts to show.')}</p>
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
