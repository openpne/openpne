import { type RefObject, useEffect, useRef, useState } from 'react';
import { carriesFiles, droppedImages, pastedImages } from '@/lib/file-drop';

interface DropTargetOptions {
    onFiles: (files: File[]) => void;
    /** While false a drop or paste is ignored, the way a disabled attach button ignores a click. */
    enabled?: boolean;
    /** Whether an image-only paste inside the target is taken as well. */
    paste?: boolean;
}

/**
 * The target is the form around `ref`, or the element itself outside any form. The drag enter/leave
 * pair is counted, since the browser fires them for every child the pointer crosses.
 */
export function useDropTarget(ref: RefObject<HTMLElement | null>, { onFiles, enabled = true, paste = false }: DropTargetOptions): boolean {
    const [dragging, setDragging] = useState(false);
    // Read through a ref so a render mid-drag does not re-bind the listeners and lose the count.
    const latest = useRef({ onFiles, enabled });
    latest.current = { onFiles, enabled };

    useEffect(() => {
        const target = ref.current?.closest('form') ?? ref.current;
        if (!target) {
            return;
        }
        let depth = 0;
        const enter = (event: DragEvent) => {
            if (!carriesFiles(event.dataTransfer)) {
                return;
            }
            event.preventDefault();
            depth += 1;
            setDragging(latest.current.enabled);
        };
        const over = (event: DragEvent) => {
            if (!carriesFiles(event.dataTransfer)) {
                return;
            }
            event.preventDefault();
            if (event.dataTransfer) {
                event.dataTransfer.dropEffect = latest.current.enabled ? 'copy' : 'none';
            }
        };
        const leave = (event: DragEvent) => {
            if (!carriesFiles(event.dataTransfer)) {
                return;
            }
            depth = Math.max(0, depth - 1);
            if (depth === 0) {
                setDragging(false);
            }
        };
        const drop = (event: DragEvent) => {
            if (!carriesFiles(event.dataTransfer)) {
                return;
            }
            event.preventDefault();
            depth = 0;
            setDragging(false);
            const files = droppedImages(event.dataTransfer);
            if (latest.current.enabled && files.length > 0) {
                latest.current.onFiles(files);
            }
        };
        const pasted = (event: ClipboardEvent) => {
            const files = pastedImages(event.clipboardData);
            if (files.length === 0) {
                return;
            }
            event.preventDefault();
            if (latest.current.enabled) {
                latest.current.onFiles(files);
            }
        };
        target.addEventListener('dragenter', enter);
        target.addEventListener('dragover', over);
        target.addEventListener('dragleave', leave);
        target.addEventListener('drop', drop);
        if (paste) {
            target.addEventListener('paste', pasted);
        }

        return () => {
            target.removeEventListener('dragenter', enter);
            target.removeEventListener('dragover', over);
            target.removeEventListener('dragleave', leave);
            target.removeEventListener('drop', drop);
            target.removeEventListener('paste', pasted);
        };
    }, [ref, paste]);

    return dragging;
}
