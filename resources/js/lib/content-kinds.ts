import { Activity, Bell, BookOpen, CalendarDays, Camera, Mail, MessageSquareText, MessagesSquare, Newspaper, UserCircle2, Users, type LucideIcon } from 'lucide-react';

// Named `t` so the i18n scanner, which recognizes keys wrapped by a `t` call, sees the label keys.
const t = (key: string): string => key;

export type ContentKind =
    | 'issue'
    | 'diary'
    | 'group'
    | 'talk'
    | 'topic'
    | 'event'
    | 'member'
    | 'timeline'
    | 'directMessage'
    | 'notification'
    | 'photo';

export interface ContentKindEntry {
    icon: LucideIcon;
    /** An i18n key for one item of the kind, translated by the consumer. */
    label: string;
}

/** See docs/internals/modern-frame.md, "Content kinds". */
export const CONTENT_KINDS: Record<ContentKind, ContentKindEntry> = {
    issue: { icon: Newspaper, label: t('What happened') },
    diary: { icon: BookOpen, label: t('%Diary%') },
    group: { icon: Users, label: t('%Community%') },
    talk: { icon: MessagesSquare, label: t('Talk') },
    topic: { icon: MessageSquareText, label: t('%Topic%') },
    event: { icon: CalendarDays, label: t('Event') },
    member: { icon: UserCircle2, label: t('Member') },
    timeline: { icon: Activity, label: t('%Activity%') },
    directMessage: { icon: Mail, label: t('Message') },
    notification: { icon: Bell, label: t('Notification') },
    photo: { icon: Camera, label: t('Photo') },
};
