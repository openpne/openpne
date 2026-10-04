/** Whether a drag carries files at all: a dragged selection of text or a link carries none and is left to the browser. */
export function carriesFiles(transfer: DataTransfer | null): boolean {
    return Array.from(transfer?.types ?? []).includes('Files');
}

/** The pictures among dropped files; what is not a picture is ignored rather than refused. */
export function droppedImages(transfer: DataTransfer | null): File[] {
    return Array.from(transfer?.files ?? []).filter((file) => file.type.startsWith('image/'));
}

/**
 * The pictures a paste carries, and none when it carries plain text: a copy from a spreadsheet or a
 * document puts both on the clipboard, and the text is what was meant.
 */
export function pastedImages(clipboard: DataTransfer | null): File[] {
    if (clipboard === null || clipboard.getData('text/plain') !== '') {
        return [];
    }

    return Array.from(clipboard.files).filter((file) => file.type.startsWith('image/'));
}

/**
 * Hands dropped files to a file input as if they were picked, so its own label, its `required` and
 * its change handler all see them; a single-file input takes the first.
 */
export function assignToInput(input: HTMLInputElement, files: File[]): void {
    const transfer = new DataTransfer();
    for (const file of input.multiple ? files : files.slice(0, 1)) {
        transfer.items.add(file);
    }
    input.files = transfer.files;
    input.dispatchEvent(new Event('change', { bubbles: true }));
}
