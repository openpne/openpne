import { Copy } from 'lucide-react';
import { useRef } from 'react';
import { ActionSheet, SHEET_GROUP, SHEET_ITEM } from './action-sheet';
import { canCopyText } from './row-sheet';
import { AiChip } from '@/components/ai-chip';
import { Avatar } from '@/components/avatar';
import { Timestamp } from '@/components/timestamp';
import { useT } from '@/lib/i18n';

export interface SelectableText {
    body: string;
    /** Null for a withdrawn author; absent where the row names nobody. */
    author?: { id: number; name: string; imageUrl: string | null; avatarColor: string | null; isAi: boolean } | null;
    createdAt?: string;
}

/**
 * A row suppresses the selection lens, so this is the one place a finger can select part of its body: the row drawn again, its body selected as it appears.
 * Drawn in place: iOS paints a selection made during a slide-in where the content stood at that instant, and one made after it comes late.
 */
export function SelectTextSheet({ text, returnFocusTo, onClose }: { text: SelectableText; returnFocusTo: HTMLElement | null; onClose: () => void }) {
    const t = useT();
    const body = useRef<HTMLParagraphElement>(null);
    const selectAll = () => {
        const selection = window.getSelection();
        if (selection !== null && body.current !== null) {
            selection.selectAllChildren(body.current);
        }
    };

    return (
        <ActionSheet open titleVisible animated={false} onOpened={selectAll} onOpenChange={(next) => !next && onClose()} title={t('Select text')} returnFocusTo={{ current: returnFocusTo }}>
            <div className="min-h-[40vh] space-y-3">
                {text.author !== undefined && (
                    <div className="flex items-center gap-2 text-sm text-muted-foreground">
                        <Avatar id={text.author?.id ?? 0} name={text.author?.name ?? ''} src={text.author?.imageUrl ?? null} color={text.author?.avatarColor ?? null} isAi={text.author?.isAi ?? false} size="md" decorative />
                        <span className="truncate text-foreground">{text.author?.name ?? t('Withdrawn member')}</span>
                        <AiChip isAi={text.author?.isAi ?? false} />
                        {text.createdAt !== undefined && <Timestamp at={text.createdAt} preset="relative" className="shrink-0" />}
                    </div>
                )}
                {/* Focusable because a long body scrolls inside the sheet, and a keyboard must be able to reach what it scrolls. */}
                <p ref={body} tabIndex={0} className="max-h-[40vh] select-text overflow-y-auto whitespace-pre-wrap break-words text-base [-webkit-touch-callout:default] [-webkit-user-select:text]">
                    {text.body}
                </p>
                {canCopyText(text.body) && (
                    <div className={SHEET_GROUP}>
                        <button
                            type="button"
                            className={SHEET_ITEM}
                            onClick={() => {
                                void navigator.clipboard.writeText(text.body).catch(() => {});
                                onClose();
                            }}
                        >
                            <Copy className="size-5 shrink-0" aria-hidden />
                            {t('Copy all text')}
                        </button>
                    </div>
                )}
            </div>
        </ActionSheet>
    );
}
