import { List, Panel } from '@/components/ui/surface';
import { ActivityRow } from '../community/activity-row';
import { DiaryRow } from '../diary/diary-row';
import { TimelineRow } from '../timeline/timeline-row';
import type { IssueBrief } from './types';

/**
 * The rest of the issue, under its lead: one row per item, in rank order.
 *
 * The rows are the ones the rest of the surface already lists these records with, not a shape of the
 * issue's own — a member who recognises a diary row on the home digest recognises it here, and the
 * byline rules each row carries (a group is the subject of a board row, an author of the others)
 * hold without being restated.
 */
export function StoryBriefs({ briefs }: { briefs: IssueBrief[] }) {
    return (
        <Panel flush>
            <List>
                {briefs.map((brief) => {
                    const key = `${brief.kind}-${brief.item.id}`;

                    if (brief.kind === 'diary') {
                        return <DiaryRow key={key} diary={brief.item} />;
                    }
                    if (brief.kind === 'timeline') {
                        return <TimelineRow key={key} post={brief.item} />;
                    }

                    return <ActivityRow key={key} entry={brief.item} />;
                })}
            </List>
        </Panel>
    );
}
