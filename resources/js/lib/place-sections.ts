import type { ComponentType } from 'react';
import { LayoutGrid } from 'lucide-react';
import { CONTENT_KINDS } from './content-kinds.ts';
import type { ChromeLabel, ChromeScope } from './member-chrome.ts';

// Named `t` so the i18n scanner, which recognizes keys wrapped by a `t` call, sees the label keys.
const t = (key: string): ChromeLabel => ({ key });

type Icon = ComponentType<{ className?: string; strokeWidth?: number; 'aria-hidden'?: boolean }>;

/** A place's top stands in no section: it names the place in its own hero, not in the header. */
export type GroupSection = 'talk' | 'topic' | 'event' | 'members';

/** The boards of a group the viewer may open, each already folded with its unit's switch (GroupPlace::openSections). */
export interface GroupOpenSections {
    talk: boolean;
    topic: boolean;
    event: boolean;
}

interface PlaceSectionEntry<K extends string> {
    key: K | 'top';
    icon: Icon;
    label: ChromeLabel;
    href: (id: number) => string;
    /** The component of the section's own list: the one screen whose tile is the page itself. */
    index?: string;
}

// The top's grid names no kind, so it is not read from CONTENT_KINDS (docs/internals/modern-frame.md, "Content kinds").
const TOP = { key: 'top', icon: LayoutGrid, label: t('Top page') } as const;

const GROUP_SECTIONS: PlaceSectionEntry<GroupSection>[] = [
    { ...TOP, href: (id) => `/groups/${id}` },
    { key: 'talk', icon: CONTENT_KINDS.talk.icon, label: t('Talk'), href: (id) => `/groups/${id}/talk`, index: 'group/talk/index' },
    { key: 'topic', icon: CONTENT_KINDS.topic.icon, label: t('%Topics%'), href: (id) => `/groups/${id}/topics`, index: 'group/topic/index' },
    { key: 'event', icon: CONTENT_KINDS.event.icon, label: t('Events'), href: (id) => `/groups/${id}/events`, index: 'group/event/index' },
    { key: 'members', icon: CONTENT_KINDS.member.icon, label: t('Members'), href: (id) => `/groups/${id}/members`, index: 'community/members' },
];

export interface PlaceSectionItem {
    key: string;
    icon: Icon;
    label: ChromeLabel;
    href: string;
    /** `aria-current`: `page` on the section's own list, `true` deeper inside the section, absent elsewhere. */
    current?: 'page' | 'true';
}

/** The sections the viewer may open, in the table's order. */
export function placeSections(scope: ChromeScope, component: string): PlaceSectionItem[] {
    if (scope.kind !== 'group') {
        return [];
    }

    return GROUP_SECTIONS.filter(({ key }) => key === 'top' || key === 'members' || scope.open[key]).map(({ key, icon, label, href, index }) => ({
        key,
        icon,
        label,
        href: href(scope.id),
        current: key !== scope.section ? undefined : component === index ? 'page' : 'true',
    }));
}
