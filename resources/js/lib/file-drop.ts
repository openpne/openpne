/** Whether a drag carries files at all: a dragged selection of text or a link carries none and is left to the browser. */
export function carriesFiles(transfer: DataTransfer | null): boolean {
    return Array.from(transfer?.types ?? []).includes('Files');
}

/**
 * Whether a drag is a pick a target should offer to take: files without markup. A picture dragged
 * off a web page arrives with its markup (`text/html`) and, in Chromium, a copy of its bytes as a
 * file — a link, not a pick — where a file dragged from the desktop carries no markup.
 */
export function carriesPick(transfer: DataTransfer | null): boolean {
    const types = Array.from(transfer?.types ?? []);

    return types.includes('Files') && !types.includes('text/html');
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

/** Whether `element` takes typed text, so a dropped link's text has somewhere to land. */
export function takesText(element: Element | null): boolean {
    if (element === null) {
        return false;
    }
    if (element instanceof HTMLElement && element.isContentEditable) {
        return true;
    }
    if (element instanceof HTMLTextAreaElement) {
        return !element.readOnly && !element.disabled;
    }
    if (element instanceof HTMLInputElement) {
        return ['text', 'search', 'url', 'email', 'tel', 'password'].includes(element.type) && !element.readOnly && !element.disabled;
    }

    return false;
}
