import type { ReactionEndpoints } from '@/lib/reactions/use-reactions';

export const timelineReactionEndpoints: ReactionEndpoints = {
    add: (id) => `/timeline/${id}/reactions`,
    remove: (id) => `/timeline/${id}/reactions/delete`,
    reactors: (id) => `/timeline/${id}/reactions`,
};
