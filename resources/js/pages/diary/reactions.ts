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
