import { Copy, Link, Reply, TextSelect, Trash2, Users } from 'lucide-react';
import { ActionSheet, SHEET_GROUP, SHEET_ITEM } from './action-sheet';
import type { ReactionChip } from '@/lib/reactions/types';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { ReactionPickerGrid } from '@/components/reactions/reaction-bar';

export { SHEET_GROUP, SHEET_ITEM };

/**
 * One answer for the sheet's item and the row's gate: split, the gate can open a sheet with nothing
 * in it. Feature-detected because the clipboard is a secure-context API — a site served over plain
 * http has no `navigator.clipboard` at all, and an offer that silently does nothing is worse than
 * none.
 */
export function canCopyText(body: string): boolean {
    return body.trim() !== '' && typeof navigator.clipboard?.writeText === 'function';
}

/** Whether a link can be offered — the clipboard alone decides; every row has an address. */
export function canCopyLink(): boolean {
    return typeof navigator.clipboard?.writeText === 'function';
}

/** Absolute from the page's origin, as every href the pages carry is. */
export function rowLink(path: string): string {
    return new URL(path, window.location.href).toString();
}

export interface RowSheetProps {
    body: string;
    /** The row's chips as it draws them — taps still on the wire included. */
    chips: ReactionChip[];
    vocabulary: string[];
    canReact: boolean;
    onToggle: (emoji: string, mine: boolean) => void;
    /** Absent for a reader the names are not offered to; given, called with the row for focus to return to. */
    onShowReactors?: (returnFocusTo: HTMLElement | null) => void;
    /** Absent where the row cannot be answered in place. */
    onReply?: () => void;
    /** Absent where the reader may not delete the row; called with the row for the confirmation to return focus to. */
    onDelete?: (returnFocusTo: HTMLElement | null) => void;
    /** Absent where the row has no address of its own. */
    link?: () => string;
    /** Where focus goes when the sheet closes: the row that was pressed. */
    returnFocusTo: HTMLElement | null;
    /** Asked to show the body on its own, selectable, once the sheet has left. */
    onSelectText: (body: string) => void;
    onClose: () => void;
    /** The sheet's name for a screen reader; the default names a post. */
    title?: string;
    /** A surface whose rows are not posts names the delete for what it removes. */
    deleteLabel?: string;
}

/** Whether a press on a row has anything to open: the sheet's own gate, so a press never raises an empty one. */
export function rowSheetOpens(props: { body: string; chips: ReactionChip[]; canReact: boolean; onShowReactors?: unknown; onReply?: unknown; onDelete?: unknown; link?: unknown }): boolean {
    return (
        props.canReact ||
        props.onReply !== undefined ||
        props.onDelete !== undefined ||
        (props.onShowReactors !== undefined && props.chips.length > 0) ||
        props.body.trim() !== '' ||
        (props.link !== undefined && canCopyLink())
    );
}

/**
 * Copying and selecting are offered to everyone, a reader who may not post included, since the row
 * suppresses the selection lens. Every choice closes the sheet before what it opens arrives: two
 * modals over each other would fight over the focus.
 */
export function RowSheet({ body, chips, vocabulary, canReact, onToggle, onShowReactors, onReply, onDelete, link, returnFocusTo, onSelectText, onClose, title, deleteLabel }: RowSheetProps) {
    const t = useT();
    const canCopy = canCopyText(body);
    const canLink = link !== undefined && canCopyLink();
    const hasBody = body.trim() !== '';

    return (
        <ActionSheet open openedByPress onOpenChange={(next) => !next && onClose()} title={title ?? t('Post actions')} returnFocusTo={{ current: returnFocusTo }}>
            {canReact && (
                // Four to a row rather than wrapping: a set meant to be scanned should not
                // change shape with its own length.
                <div className="grid grid-cols-4 justify-items-center gap-y-2 pb-2">
                    <ReactionPickerGrid
                        chips={chips}
                        vocabulary={vocabulary}
                        // A held one keeps its own colours: written after these, they replace
                        // rather than sit under.
                        buttonClassName="size-12 text-2xl border-input bg-muted"
                        onPick={(emoji, mine) => {
                            onToggle(emoji, mine);
                            onClose();
                        }}
                    />
                </div>
            )}

            {(onReply !== undefined || (onShowReactors !== undefined && chips.length > 0) || hasBody || canCopy || canLink) && (
                <div className={SHEET_GROUP}>
                    {onReply !== undefined && (
                        <button
                            type="button"
                            className={SHEET_ITEM}
                            onClick={() => {
                                onClose();
                                onReply();
                            }}
                        >
                            <Reply className="size-5 shrink-0" aria-hidden />
                            {t('Reply')}
                        </button>
                    )}

                    {onShowReactors !== undefined && chips.length > 0 && (
                        <button
                            type="button"
                            className={SHEET_ITEM}
                            onClick={() => {
                                onClose();
                                onShowReactors(returnFocusTo);
                            }}
                        >
                            <Users className="size-5 shrink-0" aria-hidden />
                            {t('See who reacted')}
                        </button>
                    )}

                    {hasBody && (
                        <button
                            type="button"
                            className={SHEET_ITEM}
                            onClick={() => {
                                onClose();
                                onSelectText(body);
                            }}
                        >
                            <TextSelect className="size-5 shrink-0" aria-hidden />
                            {t('Select text')}
                        </button>
                    )}

                    {canCopy && (
                        <button
                            type="button"
                            className={SHEET_ITEM}
                            onClick={() => {
                                // A refusal — the permission denied, the document not focused —
                                // leaves the body where it is.
                                void navigator.clipboard.writeText(body).catch(() => {});
                                onClose();
                            }}
                        >
                            <Copy className="size-5 shrink-0" aria-hidden />
                            {t('Copy text')}
                        </button>
                    )}

                    {canLink && (
                        <button
                            type="button"
                            className={SHEET_ITEM}
                            onClick={() => {
                                void navigator.clipboard.writeText(link()).catch(() => {});
                                onClose();
                            }}
                        >
                            <Link className="size-5 shrink-0" aria-hidden />
                            {t('Copy link')}
                        </button>
                    )}
                </div>
            )}

            {onDelete !== undefined && (
                // A frame of its own: the only choice here that cannot be taken back does not stand
                // among the ones that can.
                <div className={cn(SHEET_GROUP, 'mt-1')}>
                    <button
                        type="button"
                        className={cn(SHEET_ITEM, 'text-destructive')}
                        onClick={() => {
                            onClose();
                            onDelete(returnFocusTo);
                        }}
                    >
                        <Trash2 className="size-5 shrink-0" aria-hidden />
                        {deleteLabel ?? t('Delete')}
                    </button>
                </div>
            )}
        </ActionSheet>
    );
}
