import type { RowReactions } from '@/components/reactions/reaction-bar';
import type { ReactionChip } from './types';

export interface ReactionsOnPage {
    chips: (id: number, rendered: ReactionChip[]) => ReactionChip[];
    toggle: (id: number, emoji: string, mine: boolean) => void;
    showReactors: (id: number) => void;
}

/** With no page state the row is a reader's who may not react here: the chips are counts and nothing else. */
export function rowReactions(id: number, rendered: ReactionChip[], vocabulary: string[], reactions: ReactionsOnPage | null): RowReactions {
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
