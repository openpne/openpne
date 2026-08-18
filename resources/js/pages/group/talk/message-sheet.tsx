import { Copy, Trash2, Users } from 'lucide-react';
import { Dialog, DialogTitle, SheetContent } from '@/components/ui/dialog';
import type { ChatReactionChip } from '@/lib/chat/types';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { TalkReactionPickerGrid } from './reaction-bar';
import type { TalkMessage } from './types';

/** The ring is inset because the frame below clips: drawn outside, a full-width item's own would be cut away. */
const SHEET_ITEM =
    'flex min-h-12 w-full items-center gap-3 px-3 text-left text-base transition hover:bg-accent focus-visible:outline-none focus-visible:inset-ring-2 focus-visible:inset-ring-ring';

/** The frame the items stand in: a bounded panel reads as pressable where a bare row on the sheet does not. */
const SHEET_GROUP = 'overflow-hidden rounded-xl border border-border bg-card divide-y divide-border';

/**
 * Whether this body can be offered for copying — and so whether it alone earns the row a press. One
 * answer for the sheet's item and the row's gate: split, the gate can open a sheet with nothing in it.
 * Feature-detected rather than assumed: the clipboard is a secure-context API, and a site served over
 * plain http — a self-hosted one on a local network — has no `navigator.clipboard` at all. Absent,
 * the item is not offered, because an offer that silently does nothing is worse than none.
 */
export function canCopyText(body: string): boolean {
    return body.trim() !== '' && typeof navigator.clipboard?.writeText === 'function';
}

/**
 * Everything one message offers, where there is no cursor to reveal it with: the sheet a long press
 * on the row opens. The same choices the row's own controls carry, plus the one the press took away —
 * a finger cannot select text on a row that suppresses the selection lens, so copying it is offered
 * here instead, to everyone, including a reader who may not post and has nothing else to do here.
 *
 * The page owns which message this is for and hands it in whole, so a message deleted while the sheet
 * stands over it takes the sheet with it rather than leaving stale actions pointing at nothing.
 */
export function TalkMessageSheet({
    message,
    chips,
    vocabulary,
    canReact,
    onToggle,
    onShowReactors,
    onDelete,
    onClose,
}: {
    message: TalkMessage;
    /** The row's chips as it draws them — taps still on the wire included. */
    chips: ChatReactionChip[];
    vocabulary: string[];
    canReact: boolean;
    onToggle: (emoji: string, mine: boolean) => void;
    onShowReactors: () => void;
    onDelete: () => void;
    onClose: () => void;
}) {
    const t = useT();
    const canCopy = canCopyText(message.body);

    return (
        <Dialog open onOpenChange={(next) => !next && onClose()}>
            <SheetContent side="bottom" closeLabel={t('Close')} aria-describedby={undefined}>
                {/* The sheet is named for the reader who cannot see it; what is drawn is the grabber,
                    which says the same thing to everyone else and says nothing worth announcing. */}
                <DialogTitle className="sr-only">{t('Message actions')}</DialogTitle>
                {/* mb-6 walks the first row clear of the close control's corner — the picker row has
                    its own pr-10 for that, but a sheet that opens with a full-width item does not. */}
                <span aria-hidden className="mx-auto mb-6 h-1 w-10 shrink-0 rounded-full bg-border" />

                {canReact && (
                    // Held off the right edge: the sheet's close control stands over the corner this
                    // row would otherwise reach into.
                    <div className="flex flex-wrap gap-1 pr-10 pb-2">
                        <TalkReactionPickerGrid
                            chips={chips}
                            vocabulary={vocabulary}
                            // Each emoji on a tile of its own: on a sheet a thumb reaches for, a
                            // character floating on the background does not read as something to
                            // press. The border is the chip row's own, so what is pressable speaks
                            // one language — the fill alone is a shade too close to the sheet's. A
                            // held one keeps its own colours — written after these, they replace
                            // rather than sit under.
                            buttonClassName="size-11 border-input bg-muted"
                            onPick={(emoji, mine) => {
                                onToggle(emoji, mine);
                                onClose();
                            }}
                        />
                    </div>
                )}

                {(chips.length > 0 || canCopy) && (
                    <div className={SHEET_GROUP}>
                        {chips.length > 0 && (
                            <button
                                type="button"
                                className={SHEET_ITEM}
                                onClick={() => {
                                    onClose();
                                    onShowReactors();
                                }}
                            >
                                <Users className="size-5 shrink-0" aria-hidden />
                                {t('See who reacted')}
                            </button>
                        )}

                        {canCopy && (
                            <button
                                type="button"
                                className={SHEET_ITEM}
                                onClick={() => {
                                    // A refusal — the permission denied, the document not focused — leaves
                                    // the message where it is. There is nothing to say about it that the
                                    // reader cannot see for themselves when they go to paste.
                                    void navigator.clipboard.writeText(message.body).catch(() => {});
                                    onClose();
                                }}
                            >
                                <Copy className="size-5 shrink-0" aria-hidden />
                                {t('Copy text')}
                            </button>
                        )}
                    </div>
                )}

                {message.canDelete && (
                    // A frame of its own: the only choice here that cannot be taken back does not stand
                    // among the ones that can.
                    <div className={cn(SHEET_GROUP, 'mt-1')}>
                        <button
                            type="button"
                            className={cn(SHEET_ITEM, 'text-destructive')}
                            onClick={() => {
                                // The sheet leaves before the confirmation arrives — two modals over
                                // each other would fight over the focus, and the question is the
                                // page's to ask.
                                onClose();
                                onDelete();
                            }}
                        >
                            <Trash2 className="size-5 shrink-0" aria-hidden />
                            {/* The sheet names no message, so the action must say what it acts on. */}
                            {t('Delete message')}
                        </button>
                    </div>
                )}
            </SheetContent>
        </Dialog>
    );
}
