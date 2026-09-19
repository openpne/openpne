import type { MemberRef } from '@/pages/community/types';

export interface ReactionChip {
    emoji: string;
    count: number;
    mine: boolean;
}

/** Who holds one emoji on a piece of content: the exact count, and at most Reactors::PER_EMOJI names. */
export interface ReactorGroup {
    emoji: string;
    count: number;
    members: MemberRef[];
}
