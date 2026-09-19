import type { RowReactions } from '@/components/reactions/reaction-bar';
import type { ReactionChip } from '@/lib/reactions/types';
import type { ReactionEndpoints } from '@/lib/reactions/use-reactions';

export const diaryReactionEndpoints: ReactionEndpoints = {
    add: (id) => `/diary/${id}/reactions`,
    remove: (id) => `/diary/${id}/reactions/delete`,
    reactors: (id) => `/diary/${id}/reactions`,
};

export const diaryCommentReactionEndpoints: ReactionEndpoints = {
    add: (id) => `/diary/comment/${id}/reactions`,
    remove: (id) => `/diary/comment/${id}/reactions/delete`,
    reactors: (id) => `/diary/comment/${id}/reactions`,
};

/** With no `reactions` the row is a guest's on a web-public entry: the chips are counts and nothing else. */
export function rowReactions(
    id: number,
    rendered: ReactionChip[],
    vocabulary: string[],
    reactions: { chips: (id: number, rendered: ReactionChip[]) => ReactionChip[]; toggle: (id: number, emoji: string, mine: boolean) => void; showReactors: (id: number) => void } | null,
): RowReactions {
    if (reactions === null) {
        return { chips: rendered, vocabulary };
    }

    return {
        chips: reactions.chips(id, rendered),
        vocabulary,
        onToggle: (emoji, mine) => reactions.toggle(id, emoji, mine),
        onShowReactors: () => reactions.showReactors(id),
    };
}
