import type { ReactionEndpoints } from '@/lib/reactions/use-reactions';

export const topicCommentReactionEndpoints: ReactionEndpoints = {
    add: (id) => `/topics/comments/${id}/reactions`,
    remove: (id) => `/topics/comments/${id}/reactions/delete`,
    reactors: (id) => `/topics/comments/${id}/reactions`,
};

export const eventCommentReactionEndpoints: ReactionEndpoints = {
    add: (id) => `/events/comments/${id}/reactions`,
    remove: (id) => `/events/comments/${id}/reactions/delete`,
    reactors: (id) => `/events/comments/${id}/reactions`,
};
