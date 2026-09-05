import { useRef } from 'react';
import { Copy, Link, Reply, Trash2, Users } from 'lucide-react';
import { Dialog, DialogTitle, SheetContent } from '@/components/ui/dialog';
import type { ChatReactionChip } from '@/lib/chat/types';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { TalkReactionPickerGrid } from './reaction-bar';
import type { TalkMessage } from './types';

/** The ring is inset because the frame below clips: drawn outside, a full-width item's own would be cut away. */
const SHEET_ITEM =
    'flex min-h-12 w-full items-center gap-3 px-3 text-left text-base transition hover:bg-accent focus-visible:outline-none focus-visible:inset-ring-2 focus-visible:inset-ring-ring';

const SHEET_GROUP = 'overflow-hidden rounded-xl border border-border bg-card divide-y divide-border';

/**
 * One answer for the sheet's item and the row's gate: split, the gate can open a sheet with nothing
 * in it. Feature-detected because the clipboard is a secure-context API — a site served over plain
 * http has no `navigator.clipboard` at all, and an offer that silently does nothing is worse than
 * none.
 */
export function canCopyText(body: string): boolean {
    return body.trim() !== '' && typeof navigator.clipboard?.writeText === 'function';
}

/** Whether a link to a message can be offered — the clipboard alone decides; every message has an address. */
export function canCopyLink(): boolean {
    return typeof navigator.clipboard?.writeText === 'function';
}

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

/**
 * A finger cannot select text on a row that suppresses the selection lens, so copying is offered here
 * to everyone, a reader who may not post included. The page hands the message in whole, so one
 * deleted while the sheet stands over it takes the sheet with it.
 */
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
    onShowReactors: () => void;
    onReply: () => void;
    onDelete: () => void;
    onClose: () => void;
}) {
    const t = useT();
    const canCopy = canCopyText(message.body);
    const canLink = canCopyLink();
    const contentRef = useRef<HTMLDivElement>(null);

    return (
        <Dialog open onOpenChange={(next) => !next && onClose()}>
            <SheetContent
                ref={contentRef}
                tabIndex={-1}
                side="bottom"
                closeLabel={t('Close')}
                aria-describedby={undefined}
                // The trap's default first stop is the first emoji, and a focus ring drawn there
                // reads as "you hold this one" on a tile nobody pressed.
                onOpenAutoFocus={(event) => {
                    event.preventDefault();
                    // preventScroll, as the default this stands in for would have passed it.
                    contentRef.current?.focus({ preventScroll: true });
                }}
            >
                {/* The sheet is named for the reader who cannot see it; what is drawn is the grabber,
                    which says the same thing to everyone else and says nothing worth announcing. */}
                <DialogTitle className="sr-only">{t('Message actions')}</DialogTitle>
                {/* mb-6 walks whatever opens the sheet clear of the close control's corner, so no
                    first row has to hold a gap of its own for it. */}
                <span aria-hidden className="mx-auto mb-6 h-1 w-10 shrink-0 rounded-full bg-border" />

                {canReact && (
                    // Four to a row rather than wrapping: a set meant to be scanned should not
                    // change shape with its own length.
                    <div className="grid grid-cols-4 justify-items-center gap-y-2 pb-2">
                        <TalkReactionPickerGrid
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

                {(canReply || chips.length > 0 || canCopy || canLink) && (
                    <div className={SHEET_GROUP}>
                        {canReply && (
                            <button
                                type="button"
                                className={SHEET_ITEM}
                                onClick={() => {
                                    // The sheet leaves first; staging the reply focuses the composer,
                                    // which the sheet standing over the foot of the screen would cover.
                                    onClose();
                                    onReply();
                                }}
                            >
                                <Reply className="size-5 shrink-0" aria-hidden />
                                {t('Reply')}
                            </button>
                        )}

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
                                    // A refusal — the permission denied, the document not focused —
                                    // leaves the message where it is.
                                    void navigator.clipboard.writeText(message.body).catch(() => {});
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
                                    // Same bargain as the text above: a refusal leaves things as they are.
                                    void navigator.clipboard.writeText(messageLink(message.id)).catch(() => {});
                                    onClose();
                                }}
                            >
                                <Link className="size-5 shrink-0" aria-hidden />
                                {t('Copy link')}
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
