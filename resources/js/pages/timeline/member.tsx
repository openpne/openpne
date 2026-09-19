import { Head, usePage } from '@inertiajs/react';
import { LoadOlder } from '@/components/load-older';
import { ReactorsDialog } from '@/components/reactions/reactors-dialog';
import { StreamEmpty, StreamHead } from '@/components/stream-empty';
import { List, Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import { rowReactions } from '@/lib/reactions/row';
import { useReactions } from '@/lib/reactions/use-reactions';
import type { PageProps } from '@/types';
import { TimelinePostCard } from './post-card';
import { timelineReactionEndpoints } from './reactions';
import type { TimelineStream, TimelinePostAuthor } from './types';

interface MemberProps extends PageProps {
    owner: TimelinePostAuthor;
    isOwner: boolean;
    viewerId: number;
    posts: TimelineStream;
    streamGeneration: string;
    headUrl: string | null;
    reactionVocabulary: string[];
}

export default function TimelineMember() {
    const t = useT();
    const { owner, isOwner, viewerId, posts, streamGeneration, headUrl, reactionVocabulary } = usePage<MemberProps>().props;
    const reactions = useReactions(timelineReactionEndpoints, streamGeneration);
    const title = isOwner ? t('%Activity%') : t(":name's %activity%", { name: owner.name });

    return (
        <>
            <Head title={title} />
            {posts.data.length === 0 ? (
                <StreamEmpty headUrl={headUrl} empty={t('No %activity% posts to show.')} older={t('No older posts.')} />
            ) : (
                <>
                    <StreamHead headUrl={headUrl} />
                    <LoadOlder data="posts" generation={streamGeneration}>
                        <Panel flush>
                            <List>
                                {posts.data.map((post) => (
                                    <TimelinePostCard key={post.id} post={post} viewerId={viewerId} reactions={rowReactions(post.id, post.reactions, reactionVocabulary, reactions)} />
                                ))}
                            </List>
                        </Panel>
                    </LoadOlder>
                </>
            )}
            {reactions.reactorsFor !== null && <ReactorsDialog url={reactions.reactorsUrl(reactions.reactorsFor)} onClose={reactions.closeReactors} />}
        </>
    );
}
