import type { RowReactions } from '@/components/reactions/reaction-bar';
import type { ReactionEndpoints } from '@/lib/reactions/use-reactions';
import type { TimelinePostEntry } from './types';

export const timelineReactionEndpoints: ReactionEndpoints = {
    add: (id) => `/timeline/${id}/reactions`,
    remove: (id) => `/timeline/${id}/reactions/delete`,
    reactors: (id) => `/timeline/${id}/reactions`,
};

export function rowReactions(
    post: TimelinePostEntry,
    vocabulary: string[],
    reactions: { chips: (id: number, rendered: TimelinePostEntry['reactions']) => TimelinePostEntry['reactions']; toggle: (id: number, emoji: string, mine: boolean) => void; showReactors: (id: number) => void },
): RowReactions {
    return {
        chips: reactions.chips(post.id, post.reactions),
        vocabulary,
        onToggle: (emoji, mine) => reactions.toggle(post.id, emoji, mine),
        onShowReactors: () => reactions.showReactors(post.id),
    };
}
