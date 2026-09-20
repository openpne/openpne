import { RowSheet, type RowSheetProps } from './row-sheet';
import { SelectTextSheet } from './select-text-sheet';
import type { useRowSheet } from './use-row-sheet';

export type RowSheetSpec = Omit<RowSheetProps, 'returnFocusTo' | 'onSelectText' | 'onClose'>;

/** `spec` answers null for a row the page no longer has, and the sheet goes with it. */
export function RowSheetHost({ sheet, spec }: { sheet: ReturnType<typeof useRowSheet>; spec: (id: number) => RowSheetSpec | null }) {
    const pressed = sheet.press === null ? null : spec(sheet.press.id);

    return (
        <>
            {sheet.press !== null && pressed !== null && <RowSheet {...pressed} returnFocusTo={sheet.press.row} onSelectText={sheet.selectText} onClose={sheet.close} />}
            {sheet.selecting !== null && <SelectTextSheet body={sheet.selecting.body} returnFocusTo={sheet.selecting.row} onClose={sheet.closeSelect} />}
        </>
    );
}
