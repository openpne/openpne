import type { CountBadge } from '@/lib/member-chrome';

type Translate = (key: string, replacements?: Record<string, string | number>) => string;

/**
 * The dictionary is flat — no plural rules — so the singular is picked by hand at exactly one, and
 * the `:count` key carries everything else.
 */
export function badgePhrase(t: Translate, badge: CountBadge, count: number): string {
    return count === 1 ? t(badge.one.key, badge.one.replacements) : t(badge.label.key, { ...badge.label.replacements, count });
}

export function unreadMessagesPhrase(t: Translate, count: number): string {
    return count === 1 ? t('1 unread message') : t(':count unread messages', { count });
}

export function jumpToUnreadPhrase(t: Translate, count: number): string {
    return count === 1 ? t('Jump to 1 unread message') : t('Jump to :count unread messages', { count });
}
