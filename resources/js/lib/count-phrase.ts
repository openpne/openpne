import type { CountBadge } from '@/lib/member-chrome';

type Translate = (key: string, replacements?: Record<string, string | number>) => string;

/**
 * The words a count is said in. The dictionary is flat — no plural rules — so English is picked by
 * hand: the singular key at exactly one, the `:count` key for everything else. One is the count a
 * pill shows most, so it is the one a reader hears most.
 */
export function badgePhrase(t: Translate, badge: CountBadge, count: number): string {
    return count === 1 ? t(badge.one.key, badge.one.replacements) : t(badge.label.key, { ...badge.label.replacements, count });
}

/** The unread-messages phrase a room row, a tile, or a pill carries. */
export function unreadMessagesPhrase(t: Translate, count: number): string {
    return count === 1 ? t('1 unread message') : t(':count unread messages', { count });
}

/** The jump control over an unread backlog. */
export function jumpToUnreadPhrase(t: Translate, count: number): string {
    return count === 1 ? t('Jump to 1 unread message') : t('Jump to :count unread messages', { count });
}
