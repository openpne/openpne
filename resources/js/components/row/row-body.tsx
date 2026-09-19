import type { ReactNode } from 'react';
import { ReactionAdd, ReactionChipsRow, type RowReactions } from '@/components/reactions/reaction-bar';
import { cn } from '@/lib/utils';

/** See docs/internals/reactions.md, "The row". */
export function RowBody({
    reactions,
    trailing,
    contentClassName,
    children,
}: {
    reactions: RowReactions;
    /** A row without a header line puts its menu here, beside the add button. */
    trailing?: ReactNode;
    /** The spacing the content's own blocks keep between them; the column is not theirs. */
    contentClassName?: string;
    children: ReactNode;
}) {
    const controls = trailing !== undefined || reactions.onToggle !== undefined;

    return (
        <div>
            <div className={cn(controls && 'grid grid-cols-[minmax(0,1fr)_auto] gap-x-2')}>
                <div className={contentClassName}>{children}</div>
                {controls && (
                    <div className="flex items-end gap-1 self-end">
                        {trailing}
                        {reactions.onToggle !== undefined && <ReactionAdd chips={reactions.chips} vocabulary={reactions.vocabulary} onPick={reactions.onToggle} />}
                    </div>
                )}
            </div>
            <ReactionChipsRow chips={reactions.chips} onToggle={reactions.onToggle} />
        </div>
    );
}
