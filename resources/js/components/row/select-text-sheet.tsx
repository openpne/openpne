import { ActionSheet } from './action-sheet';
import { useT } from '@/lib/i18n';

/** A row suppresses the selection lens, so this is the one place a finger can select part of its body. */
export function SelectTextSheet({ body, returnFocusTo, onClose }: { body: string; returnFocusTo: HTMLElement | null; onClose: () => void }) {
    const t = useT();

    return (
        <ActionSheet open onOpenChange={(next) => !next && onClose()} title={t('Select text')} returnFocusTo={{ current: returnFocusTo }}>
            <p className="max-h-[60vh] select-text overflow-y-auto whitespace-pre-wrap break-words text-base [-webkit-touch-callout:default] [-webkit-user-select:text]">{body}</p>
        </ActionSheet>
    );
}
