import { useRef } from 'react';
import { type useShrunkPick } from '@/components/images-field';
import { assignToInput } from '@/lib/file-drop';
import { useT } from '@/lib/i18n';
import { useDropTarget } from '@/lib/use-drop-target';
import { cn } from '@/lib/utils';

/**
 * The one-picture input of a profile or group form. A drop on its form lands in the input itself,
 * so the browser's own label and `required` see the file; a drop of several takes the first.
 */
export function SingleImageInput({
    id,
    name,
    required = false,
    paste = false,
    picked,
}: {
    id: string;
    name?: string;
    required?: boolean;
    /** Whether an image-only paste into the form is taken too; a form with a body to write has one. */
    paste?: boolean;
    picked: ReturnType<typeof useShrunkPick>;
}) {
    const t = useT();
    const input = useRef<HTMLInputElement>(null);
    const dragging = useDropTarget(input, {
        onFiles: (files) => {
            if (input.current) {
                assignToInput(input.current, files);
            }
        },
        enabled: !picked.busy,
        paste,
    });

    return (
        <>
            <input
                ref={input}
                id={id}
                name={name}
                type="file"
                accept={picked.accept}
                onChange={picked.pick}
                required={required}
                className={cn(
                    'block w-full rounded-md text-sm text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-secondary file:px-3 file:py-2 file:text-sm file:text-secondary-foreground hover:file:bg-secondary/80',
                    dragging && 'ring-2 ring-ring ring-offset-2 ring-offset-background',
                )}
            />
            {dragging && <p className="mt-1 text-xs text-muted-foreground">{t('Drop a picture here')}</p>}
            {picked.busy && <p className="mt-1 text-xs text-muted-foreground">{t('Processing images…')}</p>}
        </>
    );
}
