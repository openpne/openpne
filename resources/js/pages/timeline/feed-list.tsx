import { FirstUseHint } from '@/components/row/first-use-hint';
import { RowSheetHost } from '@/components/row/row-sheet-host';
import { rowLink } from '@/components/row/row-sheet';
import { useRowSheet } from '@/components/row/use-row-sheet';
import { List, Panel } from '@/components/ui/surface';
import { rowReactions } from '@/lib/reactions/row';
import type { useReactions } from '@/lib/reactions/use-reactions';
import { useRowActionsHint } from '@/lib/use-row-actions-hint';
import { TimelinePostCard, useDeleteTimelinePost } from './post-card';
import type { TimelinePostEntry } from './types';

/** The feed's rows and the sheet a press on one raises, shared by every page that lists posts. */
export function TimelineFeedList({
    posts,
    viewerId,
    reactions,
    reactionVocabulary,
}: {
    posts: TimelinePostEntry[];
    viewerId: number;
    reactions: ReturnType<typeof useReactions>;
    reactionVocabulary: string[];
}) {
    const sheet = useRowSheet();
    const hint = useRowActionsHint();
    const deletePost = useDeleteTimelinePost();

    return (
        <>
            <FirstUseHint visible={hint.visible} onDismiss={hint.dismiss} />
            <Panel flush>
                <List>
                    {posts.map((post) => (
                        <TimelinePostCard
                            key={post.id}
                            post={post}
                            viewerId={viewerId}
                            reactions={rowReactions(post.id, post.reactions, reactionVocabulary, reactions)}
                            onOpenActions={(row) => {
                                hint.dismiss();
                                sheet.open(post.id, row);
                            }}
                        />
                    ))}
                </List>
            </Panel>
            <RowSheetHost
                sheet={sheet}
                spec={(id) => {
                    const post = posts.find((candidate) => candidate.id === id);
                    if (post === undefined) {
                        return null;
                    }

                    return {
                        body: post.body,
                        author: post.author,
                        createdAt: post.createdAt,
                        chips: reactions.chips(post.id, post.reactions),
                        vocabulary: reactionVocabulary,
                        canReact: true,
                        onToggle: (emoji, mine) => reactions.toggle(post.id, emoji, mine),
                        onShowReactors: (opener) => reactions.showReactors(post.id, undefined, opener),
                        onDelete: post.author.id === viewerId ? (opener) => void deletePost(post.id, opener) : undefined,
                        link: () => rowLink(`/timeline/${post.id}`),
                    };
                }}
            />
        </>
    );
}
