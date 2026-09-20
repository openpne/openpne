import { RowSheet, canCopyLink, canCopyText } from '@/components/row/row-sheet';
import type { SelectableText } from '@/components/row/select-text-sheet';
import type { ChatReactionChip } from '@/lib/chat/types';
import { useT } from '@/lib/i18n';
import type { TalkMessage } from './types';

export { canCopyLink, canCopyText };

/**
 * Built from the page itself, so a sub-directory install needs no telling. Any other query is
 * dropped: `context` names this visit's position, not the message's.
 */
export function messageLink(id: number): string {
    const url = new URL(window.location.href);
    url.search = '';
    url.hash = '';
    url.searchParams.set('m', String(id));

    return url.toString();
}

/** The page hands the message in whole, so one deleted while the sheet stands over it takes the sheet with it. */
export function TalkMessageSheet({
    message,
    chips,
    vocabulary,
    canReact,
    canReply,
    onToggle,
    onShowReactors,
    onReply,
    onDelete,
    returnFocusTo,
    onSelectText,
    onClose,
}: {
    message: TalkMessage;
    /** The row's chips as it draws them — taps still on the wire included. */
    chips: ChatReactionChip[];
    vocabulary: string[];
    canReact: boolean;
    /** Whether the viewer may post, and so answer this message. */
    canReply: boolean;
    onToggle: (emoji: string, mine: boolean) => void;
    onShowReactors: (returnFocusTo: HTMLElement | null) => void;
    onReply: () => void;
    onDelete: (returnFocusTo: HTMLElement | null) => void;
    returnFocusTo: HTMLElement | null;
    onSelectText: (text: SelectableText) => void;
    onClose: () => void;
}) {
    const t = useT();

    return (
        <RowSheet
            body={message.body}
            author={message.author}
            createdAt={message.createdAt}
            chips={chips}
            vocabulary={vocabulary}
            canReact={canReact}
            onToggle={onToggle}
            onShowReactors={onShowReactors}
            onReply={canReply ? onReply : undefined}
            onDelete={message.canDelete ? onDelete : undefined}
            link={() => messageLink(message.id)}
            returnFocusTo={returnFocusTo}
            onSelectText={onSelectText}
            onClose={onClose}
            title={t('Message actions')}
            // The sheet names no message, so the action must say what it acts on.
            deleteLabel={t('Delete message')}
        />
    );
}
