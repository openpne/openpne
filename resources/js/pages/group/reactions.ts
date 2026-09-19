import type { ReactionEndpoints } from '@/lib/reactions/use-reactions';

export const topicReactionEndpoints: ReactionEndpoints = {
    add: (id) => `/topics/${id}/reactions`,
    remove: (id) => `/topics/${id}/reactions/delete`,
    reactors: (id) => `/topics/${id}/reactions`,
};

export const eventReactionEndpoints: ReactionEndpoints = {
    add: (id) => `/events/${id}/reactions`,
    remove: (id) => `/events/${id}/reactions/delete`,
    reactors: (id) => `/events/${id}/reactions`,
};

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
